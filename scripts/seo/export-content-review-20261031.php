<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Only public catalogue fields. SELECT queries; no customer, order or credential data.
$products = DB::table('products')->where('status', 'active')->whereNull('deleted_at')
    ->orderBy('id')->get(['id', 'name', 'slug', 'seo_title', 'seo_description', 'short_description', 'external_source', 'external_id']);
$groups = $products->groupBy(fn ($p): string => Str::lower(Str::squish(strip_tags($p->seo_title ?: $p->name))))
    ->filter(fn ($rows): bool => $rows->count() > 1);
$ids = $groups->flatten(1)->pluck('id')->all();
$productValues = collect();
$variantValues = collect();
if ($ids !== []) {
    $productValues = DB::table('product_attribute_value as pivot')
        ->join('attribute_values as value', 'value.id', '=', 'pivot.attribute_value_id')
        ->join('attributes as attribute', 'attribute.id', '=', 'value.attribute_id')
        ->whereIn('pivot.product_id', $ids)->orderBy('pivot.product_id')->orderBy('attribute.id')->orderBy('value.id')
        ->get(['pivot.product_id', 'attribute.name as attribute', 'value.value'])->groupBy('product_id');
    $variantValues = DB::table('product_variants as variant')
        ->join('product_variant_attribute_value as pivot', 'pivot.product_variant_id', '=', 'variant.id')
        ->join('attribute_values as value', 'value.id', '=', 'pivot.attribute_value_id')
        ->join('attributes as attribute', 'attribute.id', '=', 'value.attribute_id')
        ->whereIn('variant.product_id', $ids)->where('variant.status', 'active')->whereNull('variant.deleted_at')
        ->select(['variant.product_id', 'attribute.name as attribute', 'value.value'])->distinct()
        ->orderBy('variant.product_id')->orderBy('attribute.name')->orderBy('value.value')
        ->get()->groupBy('product_id');
}
$counts = DB::table('category_product as assignment')
    ->join('products', 'products.id', '=', 'assignment.product_id')
    ->where('products.status', 'active')->whereNull('products.deleted_at')
    ->select('assignment.category_id')->selectRaw('COUNT(DISTINCT products.id) as active_assigned_products')
    ->groupBy('assignment.category_id')->pluck('active_assigned_products', 'category_id');
$categories = DB::table('categories')->where('status', 'active')->whereNull('deleted_at')->orderBy('id')
    ->get(['id', 'parent_id', 'name', 'slug', 'description', 'seo_title', 'seo_description'])
    ->map(function ($category) use ($counts) {
        $category->active_assigned_products = (int) $counts->get($category->id, 0);

        return $category;
    });
$data = [
    'exported_at' => now()->toIso8601String(),
    'database_writes' => false,
    'active_products' => $products->count(),
    'active_categories' => $categories->count(),
    'categories' => $categories,
    'exact_duplicate_title_groups' => $groups->count(),
    'duplicate_groups' => $groups->map(fn ($rows): array => $rows->map(function ($product) use ($productValues, $variantValues) {
        $product->product_attributes = $productValues->get($product->id, collect())->values();
        $product->active_variant_attribute_values = $variantValues->get($product->id, collect())->values();

        return $product;
    })->values()->all())->values(),
];
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
