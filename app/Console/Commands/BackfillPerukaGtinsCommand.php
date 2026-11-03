<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Peruka\PerukaProductImporter;
use App\Services\Storefront\StorefrontCache;
use App\Support\Seo\ProductIdentifiers;
use Illuminate\Console\Command;

final class BackfillPerukaGtinsCommand extends Command
{
    protected $signature = 'peruka:backfill-gtins
        {--data=scrapers/peruka/full-peruka-product-data.json : Peruka product-data JSON path. Relative paths are resolved under storage/app.}
        {--write : Store the GTINs. Otherwise preview only.}';

    protected $description = 'Store the EAN from scraped Peruka product data as the GTIN of each imported product\'s default variant.';

    public function handle(PerukaProductImporter $importer, StorefrontCache $cache): int
    {
        $path = str_starts_with((string) $this->option('data'), '/')
            ? (string) $this->option('data')
            : storage_path('app/'.ltrim((string) $this->option('data'), '/'));
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($decoded['products'] ?? null)) {
            $this->error('PERUKA_DATA_NOT_FOUND='.$path);

            return self::FAILURE;
        }

        $candidates = [];
        $counts = ['with_ean' => 0, 'invalid' => 0, 'duplicate' => 0, 'not_imported' => 0, 'already_set' => 0, 'stored' => 0];

        foreach ($decoded['products'] as $productData) {
            $ean = is_array($productData) ? preg_replace('/\D/', '', (string) ($productData['ean'] ?? '')) : '';

            if ($ean === '') {
                continue;
            }

            $counts['with_ean']++;

            if (! ProductIdentifiers::isValidGtin($ean)) {
                $counts['invalid']++;

                continue;
            }

            $candidates[$ean][] = $importer->externalProductId($productData);
        }

        $write = (bool) $this->option('write');

        foreach ($candidates as $gtin => $externalIds) {
            // One GTIN identifies one product; an EAN shared by several Peruka products cannot be trusted.
            if (count(array_unique($externalIds)) > 1) {
                $counts['duplicate'] += count($externalIds);

                continue;
            }

            $variant = ProductVariant::query()
                ->where('external_variant_id', 'peruka-'.$externalIds[0].'-default')
                ->whereIn('product_id', Product::query()->where('external_source', 'peruka')->where('external_id', $externalIds[0])->select('id'))
                ->first();

            if ($variant === null) {
                $counts['not_imported']++;

                continue;
            }

            if (filled($variant->gtin)) {
                $counts['already_set']++;

                continue;
            }

            if ($write) {
                $variant->forceFill(['gtin' => (string) $gtin])->save();
            }

            $counts['stored']++;
        }

        $this->line('=== PERUKA GTINS ===');
        $this->line('FILE_PRODUCTS='.count($decoded['products']));
        $this->line('WITH_EAN='.$counts['with_ean']);
        $this->line('INVALID_CHECK_DIGIT='.$counts['invalid']);
        $this->line('SHARED_BY_SEVERAL_PRODUCTS='.$counts['duplicate']);
        $this->line('NOT_IMPORTED='.$counts['not_imported']);
        $this->line('ALREADY_SET='.$counts['already_set']);
        $this->line(($write ? 'STORED=' : 'TO_STORE=').$counts['stored']);

        if (! $write) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);
        $this->info('PERUKA_GTINS_WRITE=PASS');

        return self::SUCCESS;
    }
}
