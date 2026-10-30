<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Http\Controllers\ProductShowController;
use App\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$products = DB::table('products')->where('status', 'active')->whereNull('deleted_at')
    ->orderBy('id')->get(['id', 'name', 'slug', 'seo_title', 'seo_description', 'short_description', 'description', 'published_at', 'external_source']);
$categories = DB::table('categories')->where('status', 'active')->whereNull('deleted_at')
    ->orderBy('id')->get(['id', 'name', 'slug', 'seo_title', 'seo_description', 'description']);
$missingProductDescriptions = $products->filter(fn ($p): bool => ! filled($p->seo_description) && ! filled($p->short_description) && ! filled($p->description));
$missingCategoryDescriptions = $categories->filter(fn ($c): bool => ! filled($c->seo_description) && ! filled($c->description));
$duplicateTitles = $products->groupBy(fn ($p): string => Str::lower(Str::limit(Str::squish(strip_tags($p->seo_title ?: $p->name)), 70, '')))
    ->filter(fn ($group): bool => $group->count() > 1);
$pricedIds = DB::table('product_variants')->where('status', 'active')->whereNull('deleted_at')
    ->where(fn ($q) => $q->where('price_gross_amount', '>', 0)->orWhere('price_net_amount', '>', 0))
    ->distinct()->pluck('product_id')->flip();
$unpricedProducts = $products->reject(fn ($p): bool => $pricedIds->has($p->id));
$sourceBreakdown = $products->groupBy(fn ($p): string => $p->external_source ?: '(not set)')
    ->map(fn ($group, $source): array => [
        'source' => $source,
        'active_products' => $group->count(),
        'without_active_priced_variant' => $group->reject(fn ($p): bool => $pricedIds->has($p->id))->count(),
        'without_publication_date' => $group->whereNull('published_at')->count(),
        'without_any_description' => $group->filter(fn ($p): bool => ! filled($p->seo_description) && ! filled($p->short_description) && ! filled($p->description))->count(),
    ])->sortByDesc('active_products')->values()->all();
$exactDuplicateTitles = $products->groupBy(fn ($p): string => Str::lower(Str::squish(strip_tags($p->seo_title ?: $p->name))))
    ->filter(fn ($group): bool => $group->count() > 1);
$summary = [
    'database_writes' => false,
    'active_products' => $products->count(),
    'active_categories' => $categories->count(),
    'products_without_explicit_seo_title' => $products->filter(fn ($p): bool => ! filled($p->seo_title))->count(),
    'products_without_explicit_seo_description' => $products->filter(fn ($p): bool => ! filled($p->seo_description))->count(),
    'products_without_any_description' => $missingProductDescriptions->count(),
    'products_without_active_priced_variant' => $unpricedProducts->count(),
    'source_breakdown' => $sourceBreakdown,
    'products_without_any_description_details' => $missingProductDescriptions->map(fn ($p): array => ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug])->values()->all(),
    'unpriced_product_samples' => $unpricedProducts->take(20)->map(fn ($p): array => ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'source' => $p->external_source])->values()->all(),
    'duplicate_untruncated_product_title_groups' => $exactDuplicateTitles->count(),
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
// This is a controller/view probe; compare it with the real HTTP status below.
$product = Product::query()->whereKey(12003)->where('status', ProductStatus::ACTIVE)->first();
if ($product !== null && $product->slug === 'mediven-forte-podkolan-wki-uciskowe-1') {
    $request = Request::create('https://staging.ortezka.pl/products/'.$product->slug, 'GET');
    // Reproduce the view context normally supplied by web middleware.
    // Do not start or save a session: the diagnostic must not persist one.
    $request->setLaravelSession($app->make('session')->driver());
    $app->make('view')->share('errors', new ViewErrorBag);
    $app->instance('request', $request);
    $app->make('url')->setRequest($request);
    try {
        $html = $app->make(ProductShowController::class)->__invoke($product)->render();
        echo 'PRODUCT_12003_CONTROLLER_RENDER=PASS BYTES='.strlen($html).PHP_EOL;
    } catch (Throwable $exception) {
        while ($exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }
        echo 'PRODUCT_12003_CONTROLLER_RENDER=FAIL'.PHP_EOL;
        echo 'EXCEPTION_CLASS='.get_class($exception).PHP_EOL;
        echo 'EXCEPTION_MESSAGE='.preg_replace('/\s+/', ' ', $exception->getMessage()).PHP_EOL;
        echo 'EXCEPTION_FILE='.$exception->getFile().':'.$exception->getLine().PHP_EOL;
        foreach (array_slice($exception->getTrace(), 0, 6) as $frame) {
            echo 'TRACE='.($frame['file'] ?? 'internal').':'.($frame['line'] ?? 0).' '.($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '').PHP_EOL;
        }
    }
} else {
    echo 'PRODUCT_12003_CONTROLLER_RENDER=SKIP_IDENTITY_NOT_FOUND'.PHP_EOL;
}
echo 'DATABASE_WRITES=0'.PHP_EOL;
