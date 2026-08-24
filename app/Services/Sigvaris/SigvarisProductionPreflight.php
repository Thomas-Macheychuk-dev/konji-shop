<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SigvarisProductionPreflight
{
    private const SOURCE = 'sigvaris';
    private const IMAGE_BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    /** @param array<string,mixed> $map @param array<string,int|string|null> $expected @return array<string,mixed> */
    public function inspect(
        array $map,
        array $expected,
        string $mapSha256,
        int $minimumFreeMiB = 500,
        int $probeImageCount = 0,
        int $probeTimeoutSeconds = 20,
    ): array {
        $checks = [];
        $errors = [];
        $review = array_values(array_filter(
            $map['review_items'] ?? [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
        $products = array_values(array_filter(
            $map['products'] ?? [],
            static fn (mixed $value): bool => is_array($value),
        ));
        $metrics = $this->catalogueMetrics($products);
        $mapErrors = array_values(array_filter(
            $map['errors'] ?? [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));

        $this->hardCheck($checks, $errors, 'mapping.source', ($map['source'] ?? null) === self::SOURCE, 'Mapping source must be sigvaris.');
        $this->hardCheck($checks, $errors, 'mapping.database_writes', ($map['database_writes'] ?? null) === false, 'Approved import map must state database_writes=false.');
        $this->hardCheck($checks, $errors, 'mapping.images_downloaded', ($map['images_downloaded'] ?? null) === false, 'Approved import map must state images_downloaded=false.');
        $this->hardCheck($checks, $errors, 'mapping.ready', ($map['ready_for_local_import_implementation'] ?? null) === true, 'Import map is not marked structurally ready.');
        $this->hardCheck($checks, $errors, 'mapping.errors', $mapErrors === [], $mapErrors === [] ? 'No hard mapping errors.' : implode(' | ', $mapErrors));

        $this->fingerprintCheck($checks, $errors, 'mapping.sha256', $mapSha256, $this->stringOrNull($expected['sha256'] ?? null), 'Import-map');
        $fingerprints = is_array($map['input_fingerprints'] ?? null) ? $map['input_fingerprints'] : [];
        $this->fingerprintCheck($checks, $errors, 'mapping.product_data_sha256', (string) ($fingerprints['product_data_sha256'] ?? ''), $this->stringOrNull($expected['product_data_sha256'] ?? null), 'Frozen product-data');
        $this->fingerprintCheck($checks, $errors, 'mapping.combinations_sha256', (string) ($fingerprints['combinations_sha256'] ?? ''), $this->stringOrNull($expected['combinations_sha256'] ?? null), 'Frozen combinations');

        foreach ([
            'products' => 'products',
            'variants' => 'variants',
            'images' => 'images',
            'category_paths' => 'category_paths',
            'downloads' => 'downloads',
            'stable_default_variants' => 'stable_default_variants',
            'vat_8_products' => 'vat_8_products',
            'vat_23_products' => 'vat_23_products',
        ] as $expectedKey => $metricKey) {
            $wanted = (int) ($expected[$expectedKey] ?? -1);
            $actual = (int) ($metrics[$metricKey] ?? 0);
            $this->hardCheck($checks, $errors, 'catalogue.'.$metricKey, $wanted < 0 || $actual === $wanted, sprintf('Expected %d; actual %d.', $wanted, $actual));
        }

        $wantedReview = (int) ($expected['review_items'] ?? -1);
        $this->hardCheck($checks, $errors, 'catalogue.review_items', $wantedReview < 0 || count($review) === $wantedReview, sprintf('Expected %d; actual %d.', $wantedReview, count($review)));
        $this->hardCheck($checks, $errors, 'catalogue.unique_product_ids', $metrics['unique_product_ids'] === $metrics['products'], sprintf('Unique product IDs: %d/%d.', $metrics['unique_product_ids'], $metrics['products']));
        $this->hardCheck($checks, $errors, 'catalogue.unique_variant_ids', $metrics['unique_variant_ids'] === $metrics['variants'], sprintf('Unique variant IDs: %d/%d.', $metrics['unique_variant_ids'], $metrics['variants']));
        $this->hardCheck($checks, $errors, 'catalogue.unique_variant_skus', $metrics['unique_variant_skus'] === $metrics['variants'], sprintf('Unique variant SKUs: %d/%d.', $metrics['unique_variant_skus'], $metrics['variants']));
        $this->hardCheck($checks, $errors, 'catalogue.unique_image_urls', $metrics['unique_image_urls'] === $metrics['images'], sprintf('Unique image URLs: %d/%d.', $metrics['unique_image_urls'], $metrics['images']));
        $this->hardCheck($checks, $errors, 'catalogue.product_invariants', $metrics['product_invariant_failures'] === [], $metrics['product_invariant_failures'] === [] ? 'All mapped products satisfy production import invariants.' : implode(' | ', $metrics['product_invariant_failures']));
        $this->hardCheck($checks, $errors, 'catalogue.prestashop_renditions', $metrics['medium_rendition_urls'] === [], $metrics['medium_rendition_urls'] === [] ? 'No medium_default PrestaShop renditions remain in the approved map.' : implode(' | ', $metrics['medium_rendition_urls']));

        $this->databaseChecks($checks, $errors, $metrics, $expected);
        $this->storageChecks($checks, $errors, max(0, $minimumFreeMiB));
        $this->deploymentConfigChecks($checks, $errors);

        if ($probeImageCount > 0) {
            $this->probeImages($checks, $errors, $metrics['image_urls'], $probeImageCount, max(1, $probeTimeoutSeconds));
        } else {
            $checks[] = ['name' => 'network.image_probe', 'status' => 'SKIPPED', 'message' => 'Image network probe was not requested.'];
        }

        return [
            'source' => self::SOURCE,
            'mode' => 'production_preflight',
            'database_writes' => false,
            'image_downloads' => false,
            'map_sha256' => $mapSha256,
            'environment' => app()->environment(),
            'metrics' => $metrics,
            'checks' => $checks,
            'errors' => array_values(array_unique($errors)),
            'review_items' => array_values(array_unique(array_map('trim', $review))),
            'ready_for_production_execution' => $errors === [],
        ];
    }

    /** @param list<array<string,mixed>> $products @return array<string,mixed> */
    private function catalogueMetrics(array $products): array
    {
        $productIds = [];
        $variantIds = [];
        $variantSkus = [];
        $slugs = [];
        $categoryPaths = [];
        $imageUrls = [];
        $mediumRenditions = [];
        $failures = [];
        $variantCount = 0;
        $imageCount = 0;
        $downloads = 0;
        $stableDefaultVariants = 0;
        $vat8Products = 0;
        $vat23Products = 0;

        foreach ($products as $index => $mapped) {
            $product = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
            $tax = is_array($mapped['tax'] ?? null) ? $mapped['tax'] : [];
            $name = $this->stringOrNull($product['name'] ?? null) ?? 'product '.($index + 1);
            $externalId = $this->stringOrNull($product['external_id'] ?? null);
            $slug = $this->stringOrNull($product['slug'] ?? null);

            if ($externalId !== null) {
                $productIds[$externalId] = true;
            }
            if ($slug !== null) {
                $slugs[$slug] = true;
            }

            if (($mapped['source'] ?? null) !== self::SOURCE || ($product['external_source'] ?? null) !== self::SOURCE) {
                $failures[] = $name.': source/external_source must be sigvaris.';
            }
            if (($product['status'] ?? null) !== 'draft') {
                $failures[] = $name.': mapped product status must be draft.';
            }
            if ($externalId === null || $slug === null) {
                $failures[] = $name.': missing external ID or slug.';
            }
            if (($tax['currency'] ?? null) !== 'PLN') {
                $failures[] = $name.': mapped tax currency must be PLN.';
            }
            if (($tax['requires_review'] ?? false) === true) {
                $failures[] = $name.': VAT must not require review in the approved Sigvaris map.';
            }

            $vat = is_numeric($tax['vat_rate'] ?? null) ? round((float) $tax['vat_rate'], 2) : null;
            if ($vat === 8.0) {
                $vat8Products++;
            } elseif ($vat === 23.0) {
                $vat23Products++;
            } else {
                $failures[] = $name.': VAT must be 8% or 23%.';
            }

            $variants = array_values(array_filter($mapped['variants'] ?? [], 'is_array'));
            $images = array_values(array_filter($mapped['images'] ?? [], 'is_array'));
            if ($variants === []) {
                $failures[] = $name.': no planned variants.';
            }
            if ($images === []) {
                $failures[] = $name.': no mapped images.';
            }

            $variantCount += count($variants);
            $imageCount += count($images);
            $downloads += is_array($mapped['downloads'] ?? null) ? count(array_filter($mapped['downloads'], 'is_array')) : 0;

            foreach ($variants as $variant) {
                $variantId = $this->stringOrNull($variant['external_variant_id'] ?? null);
                $sku = $this->stringOrNull($variant['sku'] ?? null);
                if ($variantId !== null) {
                    $variantIds[$variantId] = true;
                }
                if ($sku !== null) {
                    $variantSkus[$sku] = true;
                }
                if ($variantId === null || $sku === null) {
                    $failures[] = $name.': variant missing external ID or SKU.';
                    continue;
                }
                if (($variant['status'] ?? null) !== 'draft') {
                    $failures[] = $name.': variant status must be draft.';
                }
                if (($variant['currency'] ?? null) !== 'PLN') {
                    $failures[] = $name.': variant currency must be PLN.';
                }
                if ($externalId !== null && $variantId !== 'sigvaris-'.$externalId.'-default' && ! str_starts_with($variantId, 'sigvaris-'.$externalId.'-')) {
                    $failures[] = $name.': variant identity is outside the product namespace.';
                }
                if ($externalId !== null && $sku !== 'SIGVARIS-'.$externalId && ! str_starts_with($sku, 'SIGVARIS-'.$externalId.'-')) {
                    $failures[] = $name.': variant SKU is outside the product namespace.';
                }
                if (($variant['source_external_variant_id'] ?? null) === null) {
                    $stableDefaultVariants++;
                }
            }

            foreach ($images as $image) {
                $url = $this->stringOrNull($image['source_url'] ?? null);
                if ($url === null) {
                    $failures[] = $name.': image source URL is missing.';
                    continue;
                }
                $imageUrls[$url] = true;
                if (str_contains($url, '-medium_default/')) {
                    $mediumRenditions[] = $url;
                }
            }

            foreach ($mapped['categories'] ?? [] as $category) {
                if (! is_array($category)) {
                    continue;
                }
                $path = array_values(array_filter($category['path'] ?? [], static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
                if ($path !== []) {
                    $categoryPaths[implode(' > ', $path)] = true;
                }
            }
        }

        return [
            'products' => count($products),
            'unique_product_ids' => count($productIds),
            'product_ids' => array_keys($productIds),
            'variants' => $variantCount,
            'unique_variant_ids' => count($variantIds),
            'variant_ids' => array_keys($variantIds),
            'unique_variant_skus' => count($variantSkus),
            'variant_skus' => array_keys($variantSkus),
            'images' => $imageCount,
            'unique_image_urls' => count($imageUrls),
            'image_urls' => array_keys($imageUrls),
            'category_paths' => count($categoryPaths),
            'downloads' => $downloads,
            'stable_default_variants' => $stableDefaultVariants,
            'vat_8_products' => $vat8Products,
            'vat_23_products' => $vat23Products,
            'slugs' => array_keys($slugs),
            'medium_rendition_urls' => array_values(array_unique($mediumRenditions)),
            'product_invariant_failures' => array_values(array_unique($failures)),
        ];
    }

    /** @param list<array<string,string>> $checks @param list<string> $errors @param array<string,mixed> $metrics @param array<string,int|string|null> $expected */
    private function databaseChecks(array &$checks, array &$errors, array $metrics, array $expected): void
    {
        try {
            DB::select('select 1 as ok');
            $this->hardCheck($checks, $errors, 'database.connection', true, 'Database connection succeeded.');
        } catch (Throwable $exception) {
            $this->hardCheck($checks, $errors, 'database.connection', false, 'Database connection failed: '.$exception->getMessage());

            return;
        }

        $existingProducts = Product::withTrashed()->where('external_source', self::SOURCE)->count();
        $existingVariants = ProductVariant::withTrashed()->whereHas('product', fn ($query) => $query->withTrashed()->where('external_source', self::SOURCE))->count();
        $existingImages = ProductImage::query()->whereHas('product', fn ($query) => $query->withTrashed()->where('external_source', self::SOURCE))->count();

        foreach (['existing_products' => $existingProducts, 'existing_variants' => $existingVariants, 'existing_images' => $existingImages] as $key => $actual) {
            $wanted = (int) ($expected[$key] ?? 0);
            $this->hardCheck($checks, $errors, 'database.'.$key, $actual === $wanted, sprintf('Expected %d; actual %d.', $wanted, $actual));
        }

        $approvedProductIds = array_fill_keys($metrics['product_ids'], true);
        $unexpectedProductIds = Product::withTrashed()->where('external_source', self::SOURCE)->pluck('external_id')->filter()->map(static fn (mixed $value): string => (string) $value)->filter(static fn (string $value): bool => ! isset($approvedProductIds[$value]))->unique()->values()->all();
        $this->hardCheck($checks, $errors, 'database.existing_product_ids', $unexpectedProductIds === [], $unexpectedProductIds === [] ? 'All existing Sigvaris product IDs belong to the approved import map.' : 'Unexpected Sigvaris product IDs: '.implode(', ', $unexpectedProductIds));

        $approvedVariantIds = array_fill_keys($metrics['variant_ids'], true);
        $unexpectedVariantIds = ProductVariant::withTrashed()->whereHas('product', fn ($query) => $query->withTrashed()->where('external_source', self::SOURCE))->pluck('external_variant_id')->filter()->map(static fn (mixed $value): string => (string) $value)->filter(static fn (string $value): bool => ! isset($approvedVariantIds[$value]))->unique()->values()->all();
        $this->hardCheck($checks, $errors, 'database.existing_variant_ids', $unexpectedVariantIds === [], $unexpectedVariantIds === [] ? 'All existing Sigvaris variant IDs belong to the approved import map.' : 'Unexpected Sigvaris variant IDs: '.implode(', ', $unexpectedVariantIds));

        $approvedImageUrls = array_fill_keys($metrics['image_urls'], true);
        $unexpectedImageUrls = ProductImage::query()->whereHas('product', fn ($query) => $query->withTrashed()->where('external_source', self::SOURCE))->pluck('source_url')->filter()->map(static fn (mixed $value): string => (string) $value)->filter(static fn (string $value): bool => ! isset($approvedImageUrls[$value]))->unique()->values()->all();
        $this->hardCheck($checks, $errors, 'database.existing_image_urls', $unexpectedImageUrls === [], $unexpectedImageUrls === [] ? 'All existing Sigvaris image source URLs belong to the approved import map.' : 'Unexpected Sigvaris image source URLs: '.implode(', ', $unexpectedImageUrls));

        $slugCollisions = Product::withTrashed()->whereIn('slug', $metrics['slugs'])->where(function ($query): void {
            $query->whereNull('external_source')->orWhere('external_source', '!=', self::SOURCE);
        })->pluck('slug')->unique()->values()->all();
        $this->hardCheck($checks, $errors, 'database.slug_collisions', $slugCollisions === [], $slugCollisions === [] ? 'No non-Sigvaris product slug collisions.' : 'Colliding product slugs: '.implode(', ', $slugCollisions));

        $skuCollisions = ProductVariant::withTrashed()->whereIn('sku', $metrics['variant_skus'])->whereHas('product', function ($query): void {
            $query->withTrashed()->where(function ($productQuery): void {
                $productQuery->whereNull('external_source')->orWhere('external_source', '!=', self::SOURCE);
            });
        })->pluck('sku')->filter()->unique()->values()->all();
        $this->hardCheck($checks, $errors, 'database.variant_sku_collisions', $skuCollisions === [], $skuCollisions === [] ? 'No non-Sigvaris variant SKU collisions.' : 'Colliding variant SKUs: '.implode(', ', $skuCollisions));
    }

    /** @param list<array<string,string>> $checks @param list<string> $errors */
    private function storageChecks(array &$checks, array &$errors, int $minimumFreeMiB): void
    {
        $disk = config('filesystems.disks.public');
        $driver = is_array($disk) ? ($disk['driver'] ?? null) : null;
        $root = is_array($disk) ? ($disk['root'] ?? null) : null;
        $this->hardCheck($checks, $errors, 'storage.public_disk', is_array($disk), 'The public filesystem disk must be configured.');
        $this->hardCheck($checks, $errors, 'storage.public_driver', $driver === 'local', 'Sigvaris production import expects the public disk to use the shared local storage volume.');
        if ($driver !== 'local' || ! is_string($root) || $root === '') {
            $this->hardCheck($checks, $errors, 'storage.public_root', false, 'Public local disk root is missing.');

            return;
        }
        $this->hardCheck($checks, $errors, 'storage.public_root', is_dir($root), 'Public disk root: '.$root);
        $this->hardCheck($checks, $errors, 'storage.public_writable', is_dir($root) && is_writable($root), 'Public disk root must be writable by the app container.');
        $free = @disk_free_space($root);
        $minimumBytes = $minimumFreeMiB * 1024 * 1024;
        $this->hardCheck($checks, $errors, 'storage.free_space', is_float($free) && $free >= $minimumBytes, is_float($free) ? sprintf('Free space %.1f MiB; minimum %d MiB.', $free / 1024 / 1024, $minimumFreeMiB) : 'Unable to determine free space on the public disk.');
        try {
            $url = Storage::disk('public')->url('products/sigvaris/preflight-example.jpg');
            $this->hardCheck($checks, $errors, 'storage.public_url', is_string($url) && $url !== '', 'Public image URL generation: '.$url);
        } catch (Throwable $exception) {
            $this->hardCheck($checks, $errors, 'storage.public_url', false, 'Unable to generate public image URL: '.$exception->getMessage());
        }
    }

    /** @param list<array<string,string>> $checks @param list<string> $errors */
    private function deploymentConfigChecks(array &$checks, array &$errors): void
    {
        $path = base_path('docker-compose.prod.yml');
        if (! is_file($path)) {
            $this->hardCheck($checks, $errors, 'deployment.shared_storage', false, 'docker-compose.prod.yml is unavailable for shared-storage verification.');

            return;
        }
        $contents = (string) file_get_contents($path);
        $sharedMountCount = substr_count($contents, 'app_storage:/var/www/html/storage');
        $hasWebLink = str_contains($contents, 'ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage');
        $this->hardCheck($checks, $errors, 'deployment.shared_storage', $sharedMountCount >= 2, 'Shared app_storage mount occurrences: '.$sharedMountCount.'.');
        $this->hardCheck($checks, $errors, 'deployment.public_storage_link', $hasWebLink, 'Production web service must expose storage/app/public through public/storage.');
    }

    /** @param list<array<string,string>> $checks @param list<string> $errors @param list<string> $imageUrls */
    private function probeImages(array &$checks, array &$errors, array $imageUrls, int $count, int $timeoutSeconds): void
    {
        $selected = array_slice($imageUrls, 0, max(0, $count));
        if ($selected === []) {
            $this->hardCheck($checks, $errors, 'network.image_probe', false, 'No mapped image URLs are available for the network probe.');

            return;
        }
        $failures = [];
        foreach ($selected as $url) {
            try {
                $response = Http::timeout($timeoutSeconds)->withHeaders([
                    'User-Agent' => self::IMAGE_BROWSER_USER_AGENT,
                    'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                    'Referer' => 'https://www.sklep-sigvaris.com/',
                ])->get($url);
                $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'), 2)[0]));
                if (! $response->successful() || ! str_starts_with($contentType, 'image/') || $response->body() === '') {
                    $failures[] = $url.' (HTTP '.$response->status().', '.$contentType.')';
                }
            } catch (Throwable $exception) {
                $failures[] = $url.' ('.$exception->getMessage().')';
            }
        }
        $this->hardCheck($checks, $errors, 'network.image_probe', $failures === [], $failures === [] ? 'Successfully fetched '.count($selected).' mapped Sigvaris image(s) without storing them.' : implode(' | ', $failures));
    }

    /** @param list<array<string,string>> $checks @param list<string> $errors */
    private function fingerprintCheck(array &$checks, array &$errors, string $name, string $actual, ?string $expected, string $label): void
    {
        $passed = $expected === null || ($actual !== '' && hash_equals(strtolower($expected), strtolower($actual)));
        $this->hardCheck($checks, $errors, $name, $passed, $expected === null ? $label.' fingerprint recorded: '.$actual : $label.' SHA-256 must match the explicitly approved fingerprint.');
    }

    /** @param list<array<string,string>> $checks @param list<string> $errors */
    private function hardCheck(array &$checks, array &$errors, string $name, bool $passed, string $message): void
    {
        $checks[] = ['name' => $name, 'status' => $passed ? 'PASS' : 'FAIL', 'message' => $message];
        if (! $passed) {
            $errors[] = $name.': '.$message;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
