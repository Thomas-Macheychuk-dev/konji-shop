<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Products\ProductBrandAttribute;
use App\Services\Seni24\Seni24ProductImporter;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class BackfillSeni24BrandsCommand extends Command
{
    protected $signature = 'seni24:backfill-brands
        {--data=scrapers/seni24/products-full-v1.json : Seni24 product-data JSON path. Relative paths are resolved under the local disk, then storage/app.}
        {--write : Attach the brands. Otherwise preview only.}';

    protected $description = 'Attach the "Marka" (or cleaned "Producent") from scraped Seni24 product data as the "Producent" attribute of already imported products without a manufacturer.';

    public function handle(Seni24ProductImporter $importer, ProductBrandAttribute $brands, StorefrontCache $cache): int
    {
        $path = $this->resolvePath((string) $this->option('data'));
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($decoded['products'] ?? null)) {
            $this->error('SENI24_DATA_NOT_FOUND='.$path);

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $counts = ['with_brand' => 0, 'not_imported' => 0, 'already_branded' => 0, 'attached' => 0];
        $perBrand = [];

        foreach ($decoded['products'] as $productData) {
            $brand = is_array($productData) ? (string) $importer->brandName($productData) : '';

            if ($brand === '') {
                continue;
            }

            $counts['with_brand']++;
            $product = Product::query()
                ->where('external_source', 'seni24')
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

        $this->line('=== SENI24 BRANDS ===');
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
        $this->info('SENI24_BRANDS_WRITE=PASS');

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $relativePath = ltrim($path, '/');
        $localDiskPath = Storage::disk('local')->path($relativePath);

        return is_file($localDiskPath) ? $localDiskPath : storage_path('app/'.$relativePath);
    }
}
