<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * Publishes the Seni products added on 15 November 2026 (resources/import-data/seni24/missing-2026-11-15.txt) once
 * seni24:import has brought them in as drafts: product status active + published_at, variants active.
 * Other seni24 drafts are left alone.
 *
 * Previews by default. --write records every touched product and variant first, so --rollback restores them.
 */
final class PublishMissingSeniProductsCommand extends Command
{
    public const URL_LIST = 'import-data/seni24/missing-2026-11-15.txt';

    protected $signature = 'seni24:publish-missing
        {--write : Publish after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the products and variants recorded in a backup written by --write.}';

    protected $description = 'Preview, publish or roll back publishing the Seni products added on 15 November 2026.';

    public function handle(StorefrontCache $cache): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'), $cache);
        }

        $products = Product::query()
            ->where('external_source', 'seni24')
            ->whereIn('external_id', $this->externalIds())
            ->where('status', ProductStatus::DRAFT->value)
            ->withCount(['images', 'categories'])
            ->with(['variants' => fn ($query) => $query->where('status', ProductVariantStatus::DRAFT->value)])
            ->orderBy('id')
            ->get();

        $publishable = $products->filter(fn (Product $product): bool => $product->categories_count > 0
            && $product->variants->contains(fn (ProductVariant $variant): bool => $variant->price_gross_amount > 0));
        $variants = $publishable->flatMap(fn (Product $product) => $product->variants->filter(fn (ProductVariant $variant): bool => $variant->price_gross_amount > 0));

        $this->line('=== SENI PUBLISH (added 2026-11-15) ===');
        $this->line('MODE='.((bool) $this->option('write') ? 'WRITE' : 'PREVIEW'));
        $this->line('DRAFT_PRODUCTS='.$products->count());
        $this->line('PRODUCTS_TO_PUBLISH='.$publishable->count());
        $this->line('VARIANTS_TO_PUBLISH='.$variants->count());
        $this->line('SKIPPED_NO_CATEGORY_OR_PRICE='.($products->count() - $publishable->count()));
        $this->line('PUBLISHED_WITHOUT_IMAGES='.$publishable->where('images_count', 0)->count());

        foreach ($publishable->where('images_count', 0)->take(20) as $product) {
            $this->line('  no image: #'.$product->id.' '.$product->name);
        }

        if (! (bool) $this->option('write')) {
            $this->line('DATABASE_WRITES=0. Re-run with --write to publish.');

            return self::SUCCESS;
        }

        if ($publishable->isEmpty()) {
            $this->warn('Nothing to publish.');

            return self::SUCCESS;
        }

        $backup = [
            'command' => 'seni24:publish-missing',
            'created_at' => now()->toIso8601String(),
            'products' => $publishable->map(fn (Product $product): array => [
                'id' => $product->id,
                'status' => $product->status->value,
                'published_at' => $product->published_at?->toIso8601String(),
            ])->values()->all(),
            'variants' => $variants->map(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'status' => $variant->status->value,
            ])->values()->all(),
        ];
        $backupPath = 'catalogue-repairs/seni24-publish-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $publishedAt = now()->subSecond();

        DB::transaction(function () use ($publishable, $variants, $publishedAt): void {
            ProductVariant::query()
                ->whereKey($variants->pluck('id')->all())
                ->update(['status' => ProductVariantStatus::ACTIVE->value]);

            Product::query()
                ->whereKey($publishable->pluck('id')->all())
                ->update(['status' => ProductStatus::ACTIVE->value, 'published_at' => $publishedAt]);
        });

        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);
        $cache->bump(StorefrontCache::NAMESPACE_NAVIGATION);

        $this->info('PUBLISHED_PRODUCTS='.$publishable->count().' PUBLISHED_VARIANTS='.$variants->count());
        $this->line('BACKUP='.$backupPath);
        $this->line('Undo with: php artisan seni24:publish-missing --rollback='.$backupPath);

        return self::SUCCESS;
    }

    private function rollback(string $backupPath, StorefrontCache $cache): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('Backup not found: '.$backupPath);

            return self::FAILURE;
        }

        try {
            $backup = json_decode((string) Storage::disk('local')->get($backupPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid backup: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (($backup['command'] ?? null) !== 'seni24:publish-missing') {
            $this->error('Not a seni24:publish-missing backup: '.$backupPath);

            return self::FAILURE;
        }

        DB::transaction(function () use ($backup): void {
            foreach ($backup['variants'] ?? [] as $row) {
                ProductVariant::query()->whereKey($row['id'])->update(['status' => $row['status']]);
            }

            foreach ($backup['products'] ?? [] as $row) {
                Product::query()->whereKey($row['id'])->update([
                    'status' => $row['status'],
                    'published_at' => $row['published_at'] !== null ? now()->parse($row['published_at']) : null,
                ]);
            }
        });

        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);
        $cache->bump(StorefrontCache::NAMESPACE_NAVIGATION);

        $this->info('RESTORED_PRODUCTS='.count($backup['products'] ?? []).' RESTORED_VARIANTS='.count($backup['variants'] ?? []));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function externalIds(): array
    {
        $urls = preg_split('/\R/', (string) file_get_contents(resource_path(self::URL_LIST))) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $url): ?string => preg_match('/_([0-9]+)(?:-[0-9]+)?$/', trim($url), $m) === 1 ? $m[1] : null,
            $urls,
        )));
    }
}
