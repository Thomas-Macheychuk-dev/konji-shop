<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Http\Controllers\ProductShowController;
use App\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$products = DB::table('products')->where('status', 'active')->whereNull('deleted_at')
    ->orderBy('id')->get(['id', 'name', 'slug', 'seo_title', 'seo_description', 'short_description', 'description', 'published_at']);
$categories = DB::table('categories')->where('status', 'active')->whereNull('deleted_at')
    ->orderBy('id')->get(['id', 'name', 'slug', 'seo_title', 'seo_description', 'description']);
$missingProductDescriptions = $products->filter(fn ($p): bool => ! filled($p->seo_description) && ! filled($p->short_description) && ! filled($p->description));
$missingCategoryDescriptions = $categories->filter(fn ($c): bool => ! filled($c->seo_description) && ! filled($c->description));
$duplicateTitles = $products->groupBy(fn ($p): string => Str::lower(Str::limit(Str::squish(strip_tags($p->seo_title ?: $p->name)), 70, '')))
    ->filter(fn ($group): bool => $group->count() > 1);
$unpriced = DB::table('products')->where('products.status', 'active')->whereNull('products.deleted_at')
    ->whereNotExists(function ($query): void {
        $query->selectRaw('1')->from('product_variants')
            ->whereColumn('product_variants.product_id', 'products.id')
            ->where('product_variants.status', 'active')->whereNull('product_variants.deleted_at')
            ->where(fn ($q) => $q->where('price_gross_amount', '>', 0)->orWhere('price_net_amount', '>', 0));
    })->count();
$summary = [
    'database_writes' => false,
    'active_products' => $products->count(),
    'active_categories' => $categories->count(),
    'products_without_explicit_seo_title' => $products->filter(fn ($p): bool => ! filled($p->seo_title))->count(),
    'products_without_explicit_seo_description' => $products->filter(fn ($p): bool => ! filled($p->seo_description))->count(),
    'products_without_any_description' => $missingProductDescriptions->count(),
    'products_without_active_priced_variant' => $unpriced,
    'active_products_without_publication_date' => $products->whereNull('published_at')->count(),
    'categories_without_explicit_seo_description' => $categories->filter(fn ($c): bool => ! filled($c->seo_description))->count(),
    'categories_without_any_description' => $missingCategoryDescriptions->count(),
    'duplicate_effective_product_title_groups' => $duplicateTitles->count(),
    'duplicate_title_samples' => $duplicateTitles->take(10)->map(fn ($group): array => $group->map(fn ($p): array => ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug])->values()->all())->values()->all(),
    'category_description_samples' => $missingCategoryDescriptions->take(12)->map(fn ($c): array => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])->values()->all(),
];
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

// Isolate the known failing public product without printing unrelated logs,
// environment variables, connection details, orders or customer information.
$product = Product::query()->whereKey(12003)->where('status', ProductStatus::ACTIVE)->first();
if ($product !== null && $product->slug === 'mediven-forte-podkolan-wki-uciskowe-1') {
    $request = Request::create('https://staging.ortezka.pl/products/'.$product->slug, 'GET');
    $app->instance('request', $request);
    $app->make('url')->setRequest($request);
    try {
        $html = $app->make(ProductShowController::class)->__invoke($product)->render();
        echo 'PRODUCT_12003_RENDER=PASS BYTES='.strlen($html).PHP_EOL;
    } catch (Throwable $exception) {
        while ($exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }
        echo 'PRODUCT_12003_RENDER=FAIL'.PHP_EOL;
        echo 'EXCEPTION_CLASS='.get_class($exception).PHP_EOL;
        echo 'EXCEPTION_MESSAGE='.preg_replace('/\s+/', ' ', $exception->getMessage()).PHP_EOL;
        echo 'EXCEPTION_FILE='.$exception->getFile().':'.$exception->getLine().PHP_EOL;
        foreach (array_slice($exception->getTrace(), 0, 6) as $frame) {
            echo 'TRACE='.($frame['file'] ?? 'internal').':'.($frame['line'] ?? 0).' '.($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '').PHP_EOL;
        }
    }
} else {
    echo 'PRODUCT_12003_RENDER=SKIP_IDENTITY_NOT_FOUND'.PHP_EOL;
}
echo 'DATABASE_WRITES=0'.PHP_EOL;
