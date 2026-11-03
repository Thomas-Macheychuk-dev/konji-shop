<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Peruka\PerukaProductImporter;
use App\Services\Products\ProductBrandAttribute;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Console\Command;

final class BackfillPerukaBrandsCommand extends Command
{
    protected $signature = 'peruka:backfill-brands
        {--data=scrapers/peruka/full-peruka-product-data.json : Peruka product-data JSON path. Relative paths are resolved under storage/app.}
        {--write : Attach the brands. Otherwise preview only.}';

    protected $description = 'Attach the manufacturer from scraped Peruka product data as the "Producent" attribute of already imported products.';

    public function handle(PerukaProductImporter $importer, ProductBrandAttribute $brands, StorefrontCache $cache): int
    {
        $path = str_starts_with((string) $this->option('data'), '/')
            ? (string) $this->option('data')
            : storage_path('app/'.ltrim((string) $this->option('data'), '/'));
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($decoded['products'] ?? null)) {
            $this->error('PERUKA_DATA_NOT_FOUND='.$path);

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $counts = ['with_brand' => 0, 'not_imported' => 0, 'already_branded' => 0, 'attached' => 0];
        $perBrand = [];

        foreach ($decoded['products'] as $productData) {
            $brand = is_array($productData) ? trim((string) ($productData['brand'] ?? '')) : '';

            if ($brand === '') {
                continue;
            }

            $counts['with_brand']++;
            $product = Product::query()
                ->where('external_source', 'peruka')
                ->where('external_id', $importer->externalProductId($productData))
                ->first();

            if ($product === null) {
                $counts['not_imported']++;

                continue;
            }

            if ($brands->hasBrand($product)) {
                $counts['already_branded']++;

                continue;
            }

            if ($write && ! $brands->attach($product, $brand)) {
                continue;
            }

            $counts['attached']++;
            $perBrand[$brand] = ($perBrand[$brand] ?? 0) + 1;
        }

        arsort($perBrand);

        $this->line('=== PERUKA BRANDS ===');
        $this->line('FILE_PRODUCTS='.count($decoded['products']));
        $this->line('WITH_BRAND='.$counts['with_brand']);
        $this->line('NOT_IMPORTED='.$counts['not_imported']);
        $this->line('ALREADY_BRANDED='.$counts['already_branded']);
        $this->line(($write ? 'ATTACHED=' : 'TO_ATTACH=').$counts['attached']);

        foreach ($perBrand as $brand => $count) {
            $this->line('  '.$brand.'='.$count);
        }

        if (! $write) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        // Attribute pivots do not fire product observers, so cached product pages are invalidated here.
        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);
        $this->info('PERUKA_BRANDS_WRITE=PASS');

        return self::SUCCESS;
    }
}
