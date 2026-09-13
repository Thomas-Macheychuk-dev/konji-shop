<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use stdClass;

final class ExportSeoTargetInventoryCommand extends Command
{
    protected $signature = 'seo:target-inventory
        {--save=scrapers/seo/ortezka/target-inventory.json : Local-disk JSON output path}
        {--products-csv=scrapers/seo/ortezka/target-products.csv : Local-disk product/variant CSV output path}
        {--categories-csv=scrapers/seo/ortezka/target-categories.csv : Local-disk category CSV output path}';

    protected $description = 'Export the read-only Konji product/category SEO target inventory for legacy URL migration.';

    /** @var array<string, int> */
    private array $normalizedSkuCounts = [];

    /** @var array<string, int> */
    private array $normalizedParentSkuCounts = [];

    /** @var array<string, int> */
    private array $summary = [
        'products' => 0,
        'active_products' => 0,
        'draft_products' => 0,
        'archived_products' => 0,
        'soft_deleted_products' => 0,
        'storefront_reachable_products' => 0,
        'products_without_variants' => 0,
        'products_with_external_id' => 0,
        'products_with_external_parent_sku' => 0,
        'variants' => 0,
        'active_variants' => 0,
        'draft_variants' => 0,
        'archived_variants' => 0,
        'soft_deleted_variants' => 0,
        'variants_with_sku' => 0,
        'categories' => 0,
        'active_categories' => 0,
        'archived_categories' => 0,
        'soft_deleted_categories' => 0,
        'storefront_reachable_categories' => 0,
    ];

    public function handle(): int
    {
        try {
            $jsonRelative = $this->requiredRelativePath('save');
            $productsCsvRelative = $this->requiredRelativePath('products-csv');
            $categoriesCsvRelative = $this->requiredRelativePath('categories-csv');
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $jsonPath = $disk->path($jsonRelative);
        $productsCsvPath = $disk->path($productsCsvRelative);
        $categoriesCsvPath = $disk->path($categoriesCsvRelative);

        foreach ([$jsonPath, $productsCsvPath, $categoriesCsvPath] as $path) {
            $directory = dirname($path);

            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create SEO target inventory directory: '.$directory);
            }
        }

        $this->components->info('Building the read-only Konji SEO target inventory.');
        $this->line('Database writes: NO');
        $this->line('Redirect/runtime changes: NO');

        $jsonHandle = fopen($jsonPath.'.tmp', 'wb');
        $productsCsvHandle = fopen($productsCsvPath.'.tmp', 'wb');
        $categoriesCsvHandle = fopen($categoriesCsvPath.'.tmp', 'wb');

        if ($jsonHandle === false || $productsCsvHandle === false || $categoriesCsvHandle === false) {
            $this->closeHandle($jsonHandle);
            $this->closeHandle($productsCsvHandle);
            $this->closeHandle($categoriesCsvHandle);

            throw new RuntimeException('Unable to open one or more SEO target inventory output files.');
        }

        try {
            $this->writeProductsCsvHeader($productsCsvHandle);
            $this->writeCategoriesCsvHeader($categoriesCsvHandle);

            fwrite($jsonHandle, "{\n");
            fwrite($jsonHandle, '    "database_writes": false,'."\n");
            fwrite($jsonHandle, '    "generated_at": '.$this->json(now()->toIso8601String()).",\n");
            fwrite($jsonHandle, '    "products": ['."\n");

            $firstProduct = true;

            DB::table('products')
                ->orderBy('id')
                ->chunkById(250, function (Collection $products) use ($jsonHandle, $productsCsvHandle, &$firstProduct): void {
                    $ids = $products->pluck('id')->map(fn ($id): int => (int) $id)->all();

                    $variants = DB::table('product_variants')
                        ->whereIn('product_id', $ids)
                        ->orderBy('product_id')
                        ->orderBy('id')
                        ->get()
                        ->groupBy('product_id');

                    $categoryLinks = DB::table('category_product')
                        ->join('categories', 'categories.id', '=', 'category_product.category_id')
                        ->whereIn('category_product.product_id', $ids)
                        ->orderBy('category_product.product_id')
                        ->orderByDesc('category_product.is_primary')
                        ->orderBy('categories.id')
                        ->get([
                            'category_product.product_id',
                            'category_product.is_primary',
                            'categories.id',
                            'categories.parent_id',
                            'categories.name',
                            'categories.slug',
                            'categories.status',
                            'categories.deleted_at',
                        ])
                        ->groupBy('product_id');

                    foreach ($products as $product) {
                        $productVariants = $variants->get($product->id, collect());
                        $productCategories = $categoryLinks->get($product->id, collect());
                        $record = $this->productRecord($product, $productVariants, $productCategories);

                        if (! $firstProduct) {
                            fwrite($jsonHandle, ",\n");
                        }

                        fwrite($jsonHandle, '        '.$this->json($record, true));
                        $firstProduct = false;

                        $this->writeProductCsvRows($productsCsvHandle, $record);
                    }
                }, 'id');

            fwrite($jsonHandle, "\n    ],\n");
            fwrite($jsonHandle, '    "categories": ['."\n");

            $firstCategory = true;

            DB::table('categories')
                ->orderBy('id')
                ->chunkById(500, function (Collection $categories) use ($jsonHandle, $categoriesCsvHandle, &$firstCategory): void {
                    foreach ($categories as $category) {
                        $record = $this->categoryRecord($category);

                        if (! $firstCategory) {
                            fwrite($jsonHandle, ",\n");
                        }

                        fwrite($jsonHandle, '        '.$this->json($record, true));
                        $firstCategory = false;

                        fputcsv($categoriesCsvHandle, [
                            $record['id'],
                            $record['parent_id'],
                            $record['name'],
                            $record['slug'],
                            $record['target_path'],
                            $record['status'],
                            $record['storefront_reachable'] ? '1' : '0',
                            $record['deleted_at'],
                        ]);
                    }
                }, 'id');

            $summary = $this->finalSummary();

            fwrite($jsonHandle, "\n    ],\n");
            fwrite($jsonHandle, '    "summary": '.$this->json($summary, true)."\n");
            fwrite($jsonHandle, "}\n");
        } catch (\Throwable $exception) {
            $this->closeHandle($jsonHandle);
            $this->closeHandle($productsCsvHandle);
            $this->closeHandle($categoriesCsvHandle);
            @unlink($jsonPath.'.tmp');
            @unlink($productsCsvPath.'.tmp');
            @unlink($categoriesCsvPath.'.tmp');

            throw $exception;
        }

        fclose($jsonHandle);
        fclose($productsCsvHandle);
        fclose($categoriesCsvHandle);

        $this->replaceFile($jsonPath.'.tmp', $jsonPath);
        $this->replaceFile($productsCsvPath.'.tmp', $productsCsvPath);
        $this->replaceFile($categoriesCsvPath.'.tmp', $categoriesCsvPath);

        $this->newLine();
        $this->components->info('Konji SEO target inventory summary');
        $this->line('Products: '.$summary['products']);
        $this->line('Storefront-reachable products: '.$summary['storefront_reachable_products']);
        $this->line('Variants: '.$summary['variants']);
        $this->line('Variants with SKU: '.$summary['variants_with_sku']);
        $this->line('Distinct normalized SKUs: '.$summary['distinct_normalized_skus']);
        $this->line('Normalized SKU duplicate groups: '.$summary['normalized_sku_duplicate_groups']);
        $this->line('Products with external parent SKU: '.$summary['products_with_external_parent_sku']);
        $this->line('Distinct normalized external parent SKUs: '.$summary['distinct_normalized_external_parent_skus']);
        $this->line('Normalized external parent SKU duplicate groups: '.$summary['normalized_external_parent_sku_duplicate_groups']);
        $this->line('Categories: '.$summary['categories']);
        $this->line('Storefront-reachable categories: '.$summary['storefront_reachable_categories']);
        $this->line('JSON inventory: '.$jsonPath);
        $this->line('Product CSV: '.$productsCsvPath);
        $this->line('Category CSV: '.$categoriesCsvPath);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, stdClass>  $variants
     * @param  Collection<int, stdClass>  $categories
     * @return array<string, mixed>
     */
    private function productRecord(stdClass $product, Collection $variants, Collection $categories): array
    {
        $this->summary['products']++;
        $this->incrementStatus('products', (string) $product->status);

        $deletedAt = $this->nullableString($product->deleted_at);
        $reachable = $product->status === 'active' && $deletedAt === null;

        if ($deletedAt !== null) {
            $this->summary['soft_deleted_products']++;
        }

        if ($reachable) {
            $this->summary['storefront_reachable_products']++;
        }

        if ($variants->isEmpty()) {
            $this->summary['products_without_variants']++;
        }

        if ($this->nullableString($product->external_id) !== null) {
            $this->summary['products_with_external_id']++;
        }

        $parentSku = $this->nullableString($product->external_parent_sku);
        $normalizedParentSku = $this->matchingKey($parentSku);

        if ($parentSku !== null) {
            $this->summary['products_with_external_parent_sku']++;
        }

        if ($normalizedParentSku !== null) {
            $this->normalizedParentSkuCounts[$normalizedParentSku] = ($this->normalizedParentSkuCounts[$normalizedParentSku] ?? 0) + 1;
        }

        $variantRecords = [];

        foreach ($variants as $variant) {
            $this->summary['variants']++;
            $this->incrementStatus('variants', (string) $variant->status);

            $variantDeletedAt = $this->nullableString($variant->deleted_at);

            if ($variantDeletedAt !== null) {
                $this->summary['soft_deleted_variants']++;
            }

            $sku = $this->nullableString($variant->sku);
            $normalizedSku = $this->matchingKey($sku);

            if ($sku !== null) {
                $this->summary['variants_with_sku']++;
            }

            if ($normalizedSku !== null) {
                $this->normalizedSkuCounts[$normalizedSku] = ($this->normalizedSkuCounts[$normalizedSku] ?? 0) + 1;
            }

            $variantRecords[] = [
                'id' => (int) $variant->id,
                'sku' => $sku,
                'matching_key' => $normalizedSku,
                'status' => (string) $variant->status,
                'is_default' => (bool) $variant->is_default,
                'deleted_at' => $variantDeletedAt,
            ];
        }

        $categoryRecords = $categories
            ->map(fn (stdClass $category): array => [
                'id' => (int) $category->id,
                'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'target_path' => '/categories/'.ltrim((string) $category->slug, '/'),
                'status' => (string) $category->status,
                'is_primary' => (bool) $category->is_primary,
                'deleted_at' => $this->nullableString($category->deleted_at),
            ])
            ->values()
            ->all();

        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'slug' => (string) $product->slug,
            'target_path' => '/products/'.ltrim((string) $product->slug, '/'),
            'status' => (string) $product->status,
            'storefront_reachable' => $reachable,
            'published_at' => $this->nullableString($product->published_at),
            'deleted_at' => $deletedAt,
            'external_source' => $this->nullableString($product->external_source),
            'external_id' => $this->nullableString($product->external_id),
            'external_parent_sku' => $parentSku,
            'external_parent_sku_matching_key' => $normalizedParentSku,
            'variants' => $variantRecords,
            'categories' => $categoryRecords,
        ];
    }

    /** @return array<string, mixed> */
    private function categoryRecord(stdClass $category): array
    {
        $this->summary['categories']++;
        $this->incrementStatus('categories', (string) $category->status);

        $deletedAt = $this->nullableString($category->deleted_at);
        $reachable = $category->status === 'active' && $deletedAt === null;

        if ($deletedAt !== null) {
            $this->summary['soft_deleted_categories']++;
        }

        if ($reachable) {
            $this->summary['storefront_reachable_categories']++;
        }

        return [
            'id' => (int) $category->id,
            'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
            'name' => (string) $category->name,
            'slug' => (string) $category->slug,
            'target_path' => '/categories/'.ltrim((string) $category->slug, '/'),
            'status' => (string) $category->status,
            'storefront_reachable' => $reachable,
            'deleted_at' => $deletedAt,
        ];
    }

    /** @param resource $handle */
    private function writeProductsCsvHeader($handle): void
    {
        fputcsv($handle, [
            'product_id',
            'product_name',
            'product_slug',
            'target_path',
            'product_status',
            'storefront_reachable',
            'published_at',
            'product_deleted_at',
            'external_source',
            'external_id',
            'external_parent_sku',
            'external_parent_sku_matching_key',
            'variant_id',
            'variant_sku',
            'variant_sku_matching_key',
            'variant_status',
            'variant_is_default',
            'variant_deleted_at',
            'primary_category_id',
            'primary_category_name',
            'primary_category_slug',
            'category_ids',
            'category_slugs',
        ]);
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $record
     */
    private function writeProductCsvRows($handle, array $record): void
    {
        $categories = collect($record['categories']);
        $primaryCategory = $categories->first(fn (array $category): bool => $category['is_primary'] === true);
        $categoryIds = $categories->pluck('id')->implode('|');
        $categorySlugs = $categories->pluck('slug')->implode('|');
        $variants = $record['variants'];

        if ($variants === []) {
            $variants = [[
                'id' => null,
                'sku' => null,
                'matching_key' => null,
                'status' => null,
                'is_default' => false,
                'deleted_at' => null,
            ]];
        }

        foreach ($variants as $variant) {
            fputcsv($handle, [
                $record['id'],
                $record['name'],
                $record['slug'],
                $record['target_path'],
                $record['status'],
                $record['storefront_reachable'] ? '1' : '0',
                $record['published_at'],
                $record['deleted_at'],
                $record['external_source'],
                $record['external_id'],
                $record['external_parent_sku'],
                $record['external_parent_sku_matching_key'],
                $variant['id'],
                $variant['sku'],
                $variant['matching_key'],
                $variant['status'],
                $variant['is_default'] ? '1' : '0',
                $variant['deleted_at'],
                $primaryCategory['id'] ?? null,
                $primaryCategory['name'] ?? null,
                $primaryCategory['slug'] ?? null,
                $categoryIds,
                $categorySlugs,
            ]);
        }
    }

    /** @param resource $handle */
    private function writeCategoriesCsvHeader($handle): void
    {
        fputcsv($handle, [
            'category_id',
            'parent_id',
            'name',
            'slug',
            'target_path',
            'status',
            'storefront_reachable',
            'deleted_at',
        ]);
    }

    /** @return array<string, int> */
    private function finalSummary(): array
    {
        return [
            ...$this->summary,
            'distinct_normalized_skus' => count($this->normalizedSkuCounts),
            'normalized_sku_duplicate_groups' => count(array_filter($this->normalizedSkuCounts, fn (int $count): bool => $count > 1)),
            'distinct_normalized_external_parent_skus' => count($this->normalizedParentSkuCounts),
            'normalized_external_parent_sku_duplicate_groups' => count(array_filter($this->normalizedParentSkuCounts, fn (int $count): bool => $count > 1)),
        ];
    }

    private function incrementStatus(string $entity, string $status): void
    {
        $key = match ([$entity, $status]) {
            ['products', 'active'] => 'active_products',
            ['products', 'draft'] => 'draft_products',
            ['products', 'archived'] => 'archived_products',
            ['variants', 'active'] => 'active_variants',
            ['variants', 'draft'] => 'draft_variants',
            ['variants', 'archived'] => 'archived_variants',
            ['categories', 'active'] => 'active_categories',
            ['categories', 'archived'] => 'archived_categories',
            default => null,
        };

        if ($key !== null) {
            $this->summary[$key]++;
        }
    }

    private function matchingKey(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $value);

        if (! is_string($collapsed) || $collapsed === '') {
            return null;
        }

        return mb_strtolower($collapsed, 'UTF-8');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function requiredRelativePath(string $option): string
    {
        $path = trim((string) $this->option($option));

        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new RuntimeException(sprintf('Option --%s must be a safe relative local-disk path.', $option));
        }

        return $path;
    }

    private function replaceFile(string $temporary, string $final): void
    {
        if (is_file($final) && ! unlink($final)) {
            throw new RuntimeException('Unable to replace existing SEO target inventory file: '.$final);
        }

        if (! rename($temporary, $final)) {
            throw new RuntimeException('Unable to move SEO target inventory file into place: '.$final);
        }
    }

    /** @param resource|false|null $handle */
    private function closeHandle($handle): void
    {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    private function json(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($value, $flags);
    }
}
