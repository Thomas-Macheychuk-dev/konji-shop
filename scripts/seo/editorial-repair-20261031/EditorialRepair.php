<?php

declare(strict_types=1);

use App\Http\Controllers\ProductShowController;
use App\Models\Product;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;

final class ProductSeoEditorialRepair
{
    private const REPAIR = 'product-seo-editorial-20261031-v1';

    private const FIELDS = ['id', 'name', 'slug', 'external_source', 'external_id', 'status', 'deleted_at', 'seo_title', 'seo_description', 'description', 'short_description', 'updated_at'];

    public function __construct(private readonly array $manifest)
    {
        if (($manifest['repair'] ?? null) !== self::REPAIR || empty($manifest['entries'])) {
            throw new RuntimeException('Invalid or empty editorial manifest.');
        }
        $ids = array_column($manifest['entries'], 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Duplicate manifest product IDs.');
        }
        foreach ($manifest['entries'] as $entry) {
            if (array_keys($entry['after']) !== ['seo_title', 'seo_description']) {
                throw new RuntimeException('Only SEO title and description are writable.');
            }
            foreach ($entry['after'] as $field => $value) {
                if (! is_string($value) || ProductSeoMetadata::placeholder(ProductSeoMetadata::plain($value))
                    || ProductSeoMetadata::foreignReference($value) || ProductSeoMetadata::promotional($value)
                    || ProductSeoMetadata::plain($value) !== $value || mb_strlen($value) > ($field === 'seo_title' ? 255 : 300)) {
                    throw new RuntimeException('Invalid editorial metadata for product '.$entry['id']);
                }
            }
        }
    }

    private function identity(array $row, array $entry): void
    {
        if ($row['status'] !== 'active' || $row['deleted_at'] !== null) {
            throw new RuntimeException('Product is no longer active: '.$entry['id']);
        }
        foreach (['name', 'slug', 'external_source', 'external_id'] as $field) {
            if ($row[$field] !== $entry['expected'][$field]) {
                throw new RuntimeException('Product identity changed: '.$entry['id'].' '.$field);
            }
        }
        foreach (['description' => 12000, 'short_description' => 3000] as $field => $limit) {
            $plain = mb_substr(ProductSeoMetadata::plain($row[$field]), 0, $limit);
            if (! hash_equals($entry['source_'.$field.'_sha256'], hash('sha256', $plain))) {
                throw new RuntimeException('Editorial evidence changed: '.$entry['id'].' '.$field);
            }
        }
    }

    private function scan(bool $lock = false): array
    {
        $hash = hash_init('sha256');
        $operations = [];
        $timestamp = now()->format('Y-m-d H:i:s');
        foreach (array_chunk($this->manifest['entries'], 100) as $chunk) {
            $query = DB::table('products')->whereIn('id', array_column($chunk, 'id'))->orderBy('id');
            if ($lock) {
                $query->lockForUpdate();
            }
            $rows = $query->get(self::FIELDS)->keyBy('id');
            foreach ($chunk as $entry) {
                $object = $rows->get($entry['id']);
                if ($object === null) {
                    throw new RuntimeException('Product missing: '.$entry['id']);
                }
                $row = (array) $object;
                $this->identity($row, $entry);
                foreach (['seo_title', 'seo_description'] as $field) {
                    if ($row[$field] !== $entry['expected'][$field]) {
                        throw new RuntimeException('SEO metadata changed since export: '.$entry['id'].' '.$field);
                    }
                }
                hash_update($hash, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
                $operations[] = [
                    'id' => $row['id'], 'slug' => $row['slug'], 'name' => $row['name'],
                    'before' => array_intersect_key($row, array_flip(['seo_title', 'seo_description', 'updated_at'])),
                    'after' => $entry['after'] + ['updated_at' => $timestamp],
                ];
            }
        }
        $titles = DB::table('products')->where('status', 'active')->whereNull('deleted_at')->pluck('seo_title', 'id')->all();
        foreach ($operations as $operation) {
            $titles[$operation['id']] = $operation['after']['seo_title'];
        }
        $groups = [];
        foreach ($titles as $id => $title) {
            $groups[mb_strtolower(ProductSeoMetadata::plain($title))][] = $id;
        }

        foreach ($this->manifest['entries'] as $entry) {
            if ($entry['after']['seo_title'] !== $entry['expected']['seo_title']
                && count($groups[mb_strtolower(ProductSeoMetadata::plain($entry['after']['seo_title']))] ?? []) > 1) {
                throw new RuntimeException('Reviewed title would introduce a collision: '.$entry['id']);
            }
        }

        return [
            'repair' => self::REPAIR, 'manifest_sha256' => hash('sha256', json_encode($this->manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'source_sha256' => hash_final($hash), 'operations' => $operations,
            'stats' => ['active_products' => count($titles), 'changed_products' => count($operations), 'description_updates' => count(array_filter($operations, fn ($o): bool => $o['before']['seo_description'] !== $o['after']['seo_description'])), 'title_updates' => count(array_filter($operations, fn ($o): bool => $o['before']['seo_title'] !== $o['after']['seo_title']))],
            'reasons' => ['reviewed_editorial_copy' => count($operations)],
            'duplicate_title_product_ids' => array_values(array_filter($groups, fn ($ids): bool => count($ids) > 1)),
        ];
    }

    public function plan(): array
    {
        return $this->scan();
    }

    private function checkPlan(array $plan): void
    {
        if (($plan['repair'] ?? null) !== self::REPAIR
            || ! hash_equals(hash('sha256', json_encode($this->manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), $plan['manifest_sha256'] ?? '')
            || array_column($plan['operations'], 'id') !== array_column($this->manifest['entries'], 'id')) {
            throw new RuntimeException('Plan does not match reviewed editorial manifest.');
        }
        foreach ($plan['operations'] as $index => $operation) {
            $entry = $this->manifest['entries'][$index];
            $after = $operation['after'];
            unset($after['updated_at']);
            $before = $operation['before'];
            unset($before['updated_at']);
            if ($operation['name'] !== $entry['expected']['name'] || $operation['slug'] !== $entry['expected']['slug']
                || $after !== $entry['after'] || $before['seo_title'] !== $entry['expected']['seo_title'] || $before['seo_description'] !== $entry['expected']['seo_description']) {
                throw new RuntimeException('Tampered editorial operation.');
            }
        }
    }

    public function apply(array $plan): void
    {
        $this->checkPlan($plan);
        DB::transaction(function () use ($plan): void {
            $live = $this->scan(true);
            if (! hash_equals($plan['source_sha256'], $live['source_sha256'])) {
                throw new RuntimeException('Source changed since dry run; no updates committed.');
            }
            // Re-derive the writable values from the reviewed manifest inside the transaction.
            foreach ($live['operations'] as $index => $operation) {
                if ($operation['before'] !== $plan['operations'][$index]['before']) {
                    throw new RuntimeException('Tampered backup values.');
                }
                $after = $operation['after'];
                $after['updated_at'] = $plan['operations'][$index]['after']['updated_at'];
                if (DB::table('products')->where('id', $operation['id'])->update($after) !== 1) {
                    throw new RuntimeException('Editorial update failed: '.$operation['id']);
                }
            }
        });
    }

    public function verify(array $plan): array
    {
        $this->checkPlan($plan);
        foreach ($plan['operations'] as $index => $operation) {
            $object = DB::table('products')->where('id', $operation['id'])->first(self::FIELDS);
            if ($object === null) {
                throw new RuntimeException('Product missing after apply.');
            }
            $row = (array) $object;
            $this->identity($row, $this->manifest['entries'][$index]);
            foreach (['seo_title', 'seo_description', 'updated_at'] as $field) {
                if ($row[$field] !== $operation['after'][$field]) {
                    throw new RuntimeException('Verification mismatch: '.$operation['id'].' '.$field);
                }
            }
        }
        $blank = 0;
        $active = 0;
        DB::table('products')->where('status', 'active')->whereNull('deleted_at')->select(['id', 'seo_title', 'seo_description'])->orderBy('id')
            ->chunkById(500, function ($rows) use (&$blank, &$active): void {
                foreach ($rows as $row) {
                    $active++;
                    $blank += ProductSeoMetadata::placeholder(ProductSeoMetadata::plain($row->seo_title)) || ProductSeoMetadata::placeholder(ProductSeoMetadata::plain($row->seo_description)) ? 1 : 0;
                }
            }, 'id', 'id');
        if ($blank > 0) {
            throw new RuntimeException('Active product metadata is missing.');
        }

        return ['active_products' => $active, 'missing_metadata' => $blank, 'verified_editorial_products' => count($plan['operations'])];
    }

    public function rollback(array $plan): void
    {
        $this->checkPlan($plan);
        DB::transaction(function () use ($plan): void {
            foreach ($plan['operations'] as $index => $operation) {
                $object = DB::table('products')->where('id', $operation['id'])->lockForUpdate()->first(self::FIELDS);
                if ($object === null) {
                    throw new RuntimeException('Rollback product missing.');
                }
                $row = (array) $object;
                $this->identity($row, $this->manifest['entries'][$index]);
                foreach (['seo_title', 'seo_description', 'updated_at'] as $field) {
                    if ($row[$field] !== $operation['after'][$field]) {
                        throw new RuntimeException('Later edit detected; rollback refused.');
                    }
                }
            }
            foreach ($plan['operations'] as $operation) {
                DB::table('products')->where('id', $operation['id'])->update($operation['before']);
            }
        });
    }

    public static function invalidateCache(): void
    {
        app(StorefrontCache::class)->bump(StorefrontCache::NAMESPACE_CATALOGUE);
    }

    public function verifyRenderedMetadata(): int
    {
        $controller = app(ProductShowController::class);
        $title = new ReflectionMethod($controller, 'seoTitle');
        $description = new ReflectionMethod($controller, 'seoDescription');
        $count = 0;
        DB::table('products')->where('status', 'active')->whereNull('deleted_at')->select(['id', 'name', 'seo_title', 'seo_description'])->orderBy('id')
            ->chunkById(500, function ($rows) use (&$count, $controller, $title, $description): void {
                foreach ($rows as $row) {
                    $product = new Product;
                    $product->setRawAttributes((array) $row);
                    if ($title->invoke($controller, $product) !== $row->seo_title || $description->invoke($controller, $product) !== $row->seo_description) {
                        throw new RuntimeException('Rendered metadata differs from database: '.$row->id);
                    }
                    $count++;
                }
            }, 'id', 'id');

        return $count;
    }
}
