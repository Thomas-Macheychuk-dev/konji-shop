<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillProductPublishDatesCommand extends Command
{
    protected $signature = 'products:backfill-publish-dates
        {--write : Set the publish dates. Otherwise preview only.}';

    protected $description = 'Give active products without a published_at their created_at as publish date, so the storefront lets customers buy them.';

    public function handle(StorefrontCache $cache): int
    {
        $write = (bool) $this->option('write');
        $query = Product::query()
            ->where('status', ProductStatus::ACTIVE->value)
            ->whereNull('published_at');

        $total = (clone $query)->count();
        $perSource = (clone $query)
            ->selectRaw("COALESCE(external_source, '(none)') AS source, COUNT(*) AS products")
            ->groupBy('source')
            ->orderByDesc('products')
            ->pluck('products', 'source');

        $this->line('=== ACTIVE PRODUCTS WITHOUT PUBLISH DATE ===');
        $this->line(($write ? 'UPDATED=' : 'TO_UPDATE=').$total);

        foreach ($perSource as $source => $count) {
            $this->line('  '.$source.'='.$count);
        }

        (clone $query)->orderBy('id')->limit(20)->get(['id', 'name', 'created_at'])
            ->each(fn (Product $product) => $this->line(sprintf('  #%d %s', $product->id, $product->name)));

        if ($total > 20) {
            $this->line('  … and '.($total - 20).' more');
        }

        if (! $write) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        // created_at rather than now(), so the backfill does not push these
        // products to the top of the newest-products listings.
        $query->update(['published_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);

        // A query update fires no product observers, so cached pages are invalidated here.
        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);
        $this->info('PRODUCT_PUBLISH_DATES_WRITE=PASS');

        return self::SUCCESS;
    }
}
