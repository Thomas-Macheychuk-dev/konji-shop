<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use ZipArchive;

final class ActivateHerdegenReleaseCommand extends Command
{
    protected $signature = 'herdegen:activate-release
        {--archive= : Absolute path to the verified 18D4 release ZIP}
        {--activate : Commit publication (otherwise dry run)}
        {--exclude-variant=* : Exact external_variant_id excluded from activation}
        {--confirm-exclusion-list : Confirm the specified exclusions are complete, including an empty list}
        {--acknowledge-production-backup : Confirm the recovery checkpoint and S3 baseline are preserved}';

    protected $description = 'HERDEGEN-18H1: manifest-bound, guarded product/variant activation; dry run by default.';

    private const ARCHIVE_SHA256 = 'ce1258c94cd7a973d753761f6259225cdb9978aadc0cd575c9003d33814e755c';
    private const MANIFEST_SHA256 = '902af36ab95d5dd6416e891f7b780b2e7222741c7854f3ec070af1eecb456cf2';

    public function handle(): int
    {
        try {
            $archive = (string) $this->option('archive');
            $this->require($archive !== '' && is_file($archive) && is_readable($archive), 'Archive missing');
            $this->require(hash_file('sha256', $archive) === self::ARCHIVE_SHA256, 'Archive SHA-256 mismatch');
            $manifest = $this->manifest($archive);
            $expected = [];
            foreach ($manifest['products'] as $p) {
                $id = $p['external_id'] ?? null;
                $this->require(is_string($id) && preg_match('/^[0-9a-f]{32}$/D', $id) === 1 && ! isset($expected[$id]), 'Duplicate/invalid product identity');
                $variants = [];
                foreach ($p['variants'] ?? [] as $v) {
                    $key = $v['external_variant_id'] ?? null;
                    $this->require(is_string($key) && $key !== '' && ! isset($variants[$key]), 'Duplicate/invalid variant identity');
                    $variants[$key] = $v;
                }
                $this->require($variants !== [], 'Product with no variants');
                $expected[$id] = ['product' => $p, 'variants' => $variants];
            }
            $this->require(count($expected) === 46, 'Expected exactly 46 products');
            $expectedVariantIds = [];
            foreach ($expected as $p) {
                foreach ($p['variants'] as $key => $v) {
                    $this->require(! isset($expectedVariantIds[$key]), 'Cross-product variant identity collision');
                    $expectedVariantIds[$key] = true;
                }
            }
            $this->require(count($expectedVariantIds) === 79, 'Expected exactly 79 variants');

            $exclusions = array_values((array) $this->option('exclude-variant'));
            $this->require(count($exclusions) === count(array_unique($exclusions)), 'Duplicate exclusions');
            foreach ($exclusions as $id) {
                $this->require(isset($expectedVariantIds[$id]), 'Unknown exclusion: '.$id);
            }
            $excluded = array_fill_keys($exclusions, true);
            $activate = (bool) $this->option('activate');
            if ($activate) {
                $this->require((bool) $this->option('confirm-exclusion-list'), 'Explicit exclusion-list confirmation required');
                $this->require((bool) $this->option('acknowledge-production-backup'), 'Backup acknowledgment required');
            }
            $this->line('MODE='.($activate ? 'ACTIVATE' : 'DRY_RUN'));
            $this->line('EXCLUSIONS='.count($excluded));
            $this->line('EXPECTED_ACTIVE_VARIANTS='.(79 - count($excluded)));
            $this->line('ARCHIVE_SHA256='.self::ARCHIVE_SHA256);

            // Write and check under the same lock/transaction so the manifest comparison
            // cannot race another change to these products/variants.
            DB::transaction(function () use ($expected, $excluded, $activate): void {
                $products = Product::withTrashed()->where('external_source', 'herdegen')
                    ->orderBy('id')->lockForUpdate()->get();
                $this->require($products->count() === 46, 'Unexpected Herdegen product count');
                $this->require($products->every(fn (Product $p) => $p->deleted_at === null), 'Deleted Herdegen product');
                $this->require($products->pluck('external_id')->unique()->count() === 46, 'Duplicate product external IDs');
                $this->require($products->pluck('external_id')->sort()->values()->all() === collect(array_keys($expected))->sort()->values()->all(), 'Product identity set differs from manifest');

                $productIds = $products->pluck('id');
                $variants = ProductVariant::withTrashed()->whereIn('product_id', $productIds)
                    ->orderBy('id')->lockForUpdate()->get();
                $this->require($variants->count() === 79, 'Unexpected variant count');
                $this->require($variants->every(fn (ProductVariant $v) => $v->deleted_at === null), 'Deleted Herdegen variant');
                $byProduct = $variants->groupBy('product_id');

                $activeProducts = 0;
                foreach ($products as $product) {
                    $record = $expected[$product->external_id];
                    $this->require($product->slug === $record['product']['slug'], 'Product slug changed: '.$product->external_id);
                    $this->require($product->status === ProductStatus::DRAFT && $product->published_at === null, 'Product not a draft: '.$product->external_id);
                    $this->require($product->categories()->exists(), 'Product without category: '.$product->external_id);
                    $this->require($product->images()->exists() || $product->attributeValueImages()->exists(), 'Product without image: '.$product->external_id);
                    $rows = $byProduct->get($product->id, collect());
                    $this->require($rows->count() === count($record['variants']), 'Variant count mismatch: '.$product->external_id);
                    $this->require($rows->pluck('external_variant_id')->unique()->count() === $rows->count(), 'Duplicate variant IDs: '.$product->external_id);
                    $eligible = 0;
                    foreach ($rows as $variant) {
                        $v = $record['variants'][$variant->external_variant_id] ?? null;
                        $this->require(is_array($v), 'Unexpected variant: '.$variant->sku);
                        $this->require($variant->sku === $v['sku'], 'SKU changed: '.$variant->sku);
                        $this->require($variant->status === ProductVariantStatus::DRAFT && $variant->stock_status === StockStatus::OUT_OF_STOCK, 'Non-draft variant: '.$variant->sku);
                        $this->require($variant->price_gross_amount === $v['price_gross_amount'] && $variant->price_gross_amount > 0, 'Gross price mismatch: '.$variant->sku);
                        $this->require((int) $variant->price_net_amount === $v['price_net_amount'] && $variant->price_net_amount > 0, 'Net price mismatch: '.$variant->sku);
                        $this->require($variant->vat_rate === VatRate::VAT_8 && $v['vat_rate'] === 8 && $variant->currency === Currency::PLN && $v['currency'] === 'PLN', 'Tax/currency mismatch: '.$variant->sku);
                        $this->require($variant->attributeValues()->count() === count($v['option_values'] ?? []), 'Attribute count mismatch: '.$variant->sku);
                        if (isset($excluded[$variant->external_variant_id])) {
                            continue;
                        }
                        $eligible++;
                        if ($activate) {
                            $variant->status = ProductVariantStatus::ACTIVE;
                            $variant->stock_status = StockStatus::IN_STOCK;
                            $variant->save();
                        }
                    }
                    if ($eligible > 0) {
                        $activeProducts++;
                        if ($activate) {
                            $product->status = ProductStatus::ACTIVE;
                            $product->published_at = now()->subSecond();
                            $product->save();
                        }
                    }
                }
                $this->line('EXPECTED_ACTIVE_PRODUCTS='.$activeProducts);
                $this->require($activeProducts > 0, 'No eligible products');
                if ($activate) {
                    $this->require(
                        Product::where('external_source', 'herdegen')->where('status', ProductStatus::ACTIVE)->count() === $activeProducts,
                        'Post-update product verification failed'
                    );
                    $this->require(
                        ProductVariant::whereIn('product_id', $productIds)->where('status', ProductVariantStatus::ACTIVE)->where('stock_status', StockStatus::IN_STOCK)->count() === 79 - count($excluded),
                        'Post-update variant verification failed'
                    );
                }
            }, 1);
            $this->info($activate ? 'HERDEGEN18H1=PASS_ACTIVATION' : 'HERDEGEN18H1=PASS_DRY_RUN');
            $this->line($activate ? 'PUBLICATION=COMMITTED' : 'DATABASE_WRITES=0 PUBLICATION=HOLD');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('HERDEGEN18H1=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function manifest(string $archive): array
    {
        $zip = new ZipArchive();
        $this->require($zip->open($archive) === true, 'Cannot open ZIP');
        try {
            $jsons = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && str_ends_with($name, '.json')) {
                    $jsons[] = $name;
                }
            }
            $this->require(count($jsons) === 1, 'Expected one JSON manifest');
            $raw = $zip->getFromName($jsons[0]);
            $this->require(is_string($raw) && hash('sha256', $raw) === self::MANIFEST_SHA256, 'Manifest checksum mismatch');
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $this->require(is_array($data) && ($data['schema_version'] ?? null) === 1 && ($data['source'] ?? null) === 'herdegen', 'Manifest schema/source mismatch');
            return $data;
        } finally {
            $zip->close();
        }
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
