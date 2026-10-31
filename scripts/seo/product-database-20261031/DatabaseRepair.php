<?php

declare(strict_types=1);

use App\Services\Storefront\StorefrontCache;
use App\Http\Controllers\ProductShowController;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class ProductSeoDatabaseRepair
{
    private const FIELDS = ['id', 'name', 'slug', 'external_source', 'external_id', 'status', 'deleted_at', 'seo_title', 'seo_description', 'updated_at'];

    public function __construct(private readonly array $reviewed) {}

    private function scan(bool $lock = false): array
    {
        $hash = hash_init('sha256');
        $operations = [];
        $stats = ['active_products' => 0, 'blank_titles_before' => 0, 'blank_descriptions_before' => 0, 'changed_products' => 0, 'minimal_generated_descriptions' => 0, 'long_existing_descriptions' => 0];
        $titles = [];
        $reasons = [];
        $timestamp = now()->format('Y-m-d H:i:s');
        $query = DB::table('products')->where('status', 'active')->whereNull('deleted_at')->select(self::FIELDS)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $query->chunkById(200, function ($rows) use (&$operations, &$stats, &$titles, &$reasons, $hash, $timestamp, $lock): void {
            $attributeQuery = DB::table('product_attribute_value as pivot')
                ->join('attribute_values as value', 'value.id', '=', 'pivot.attribute_value_id')
                ->join('attributes as attribute', 'attribute.id', '=', 'value.attribute_id')
                ->whereIn('pivot.product_id', $rows->pluck('id')->all())
                ->whereIn('attribute.name', ['Model', 'Symbol', 'Numer katalogowy', 'Materiał', 'Pojemność', 'Kolor', 'Marka', 'Producent', 'Wymiary'])
                ->orderBy('pivot.product_id')->orderBy('attribute.id')->orderBy('value.id');
            if ($lock) {
                $attributeQuery->lockForUpdate();
            }
            $attributes = [];
            foreach ($attributeQuery->get(['pivot.product_id', 'attribute.name', 'value.value']) as $attribute) {
                $attributes[$attribute->product_id][$attribute->name][] = $attribute->value;
            }
            foreach ($rows as $object) {
                $row = (array) $object;
                $facts = $attributes[$row['id']] ?? [];
                hash_update($hash, json_encode([$row, $facts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
                $proposal = ProductSeoMetadata::propose($row, $facts, $this->reviewed);
                $after = $proposal['after'];
                $stats['active_products']++;
                $stats['blank_titles_before'] += ProductSeoMetadata::plain($row['seo_title']) === '' ? 1 : 0;
                $stats['blank_descriptions_before'] += ProductSeoMetadata::plain($row['seo_description']) === '' ? 1 : 0;
                $stats['minimal_generated_descriptions'] += $proposal['minimal_description'] ? 1 : 0;
                $stats['long_existing_descriptions'] += mb_strlen($after['seo_description']) > 160 ? 1 : 0;
                $titles[mb_strtolower($after['seo_title'])][] = $row['id'];
                if ($row['seo_title'] === $after['seo_title'] && $row['seo_description'] === $after['seo_description']) {
                    continue;
                }
                $before = array_intersect_key($row, array_flip(['seo_title', 'seo_description', 'updated_at']));
                $operations[] = [
                    'id' => $row['id'], 'slug' => $row['slug'], 'name' => $row['name'],
                    'before' => $before,
                    'after' => $after + ['updated_at' => $timestamp],
                    'reasons' => $proposal['reasons'],
                ];
                foreach ($proposal['reasons'] as $reason) {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                }
            }
        }, 'id', 'id');
        $stats['changed_products'] = count($operations);
        $duplicates = array_values(array_filter($titles, fn (array $ids): bool => count($ids) > 1));

        return ['repair' => 'product-seo-database-20261031-v1', 'source_sha256' => hash_final($hash), 'stats' => $stats, 'reasons' => $reasons, 'operations' => $operations, 'duplicate_title_product_ids' => $duplicates];
    }

    public function plan(): array
    {
        return $this->scan();
    }

    public function apply(array $plan): void
    {
        $this->checkPlan($plan);
        DB::transaction(function () use ($plan): void {
            $live = $this->scan(true);
            if (! hash_equals($plan['source_sha256'], $live['source_sha256'])) {
                throw new RuntimeException('Source changed after dry run; create a fresh plan. No product updates committed.');
            }
            // Only use re-derived field values; a changed plan cannot inject arbitrary writes.
            $expected = $plan['operations'];
            $derived = $live['operations'];
            foreach ($derived as &$operation) {
                unset($operation['after']['updated_at']);
            }
            unset($operation);
            foreach ($expected as &$operation) {
                unset($operation['after']['updated_at']);
            }
            unset($operation);
            if ($expected !== $derived) {
                throw new RuntimeException('Plan no longer matches the repair algorithm. No product updates committed.');
            }
            foreach ($plan['operations'] as $operation) {
                $changed = DB::table('products')->where('id', $operation['id'])->update($operation['after']);
                if ($changed !== 1) {
                    throw new RuntimeException('Update failed for product '.$operation['id']);
                }
            }
        });
    }

    public function verify(array $plan): array
    {
        $this->checkPlan($plan);
        foreach (array_chunk($plan['operations'], 200) as $chunk) {
            $rows = DB::table('products')->whereIn('id', array_column($chunk, 'id'))->get(self::FIELDS)->keyBy('id');
            foreach ($chunk as $operation) {
                $row = $rows->get($operation['id']);
                if ($row === null || $row->status !== 'active' || $row->deleted_at !== null || $row->slug !== $operation['slug'] || $row->name !== $operation['name']) {
                    throw new RuntimeException('Verification identity mismatch for product '.$operation['id']);
                }
                foreach (['seo_title', 'seo_description'] as $field) {
                    if ($row->$field !== $operation['after'][$field]) {
                        throw new RuntimeException('Verification mismatch for product '.$operation['id'].' '.$field);
                    }
                }
            }
        }
        $blankTitles = 0;
        $blankDescriptions = 0;
        $count = 0;
        DB::table('products')->where('status', 'active')->whereNull('deleted_at')->select(['id', 'seo_title', 'seo_description'])->orderBy('id')
            ->chunkById(500, function ($rows) use (&$blankTitles, &$blankDescriptions, &$count): void {
                foreach ($rows as $row) {
                    $count++;
                    $blankTitles += ProductSeoMetadata::plain($row->seo_title) === '' ? 1 : 0;
                    $blankDescriptions += ProductSeoMetadata::plain($row->seo_description) === '' ? 1 : 0;
                }
            }, 'id', 'id');
        if ($blankTitles !== 0 || $blankDescriptions !== 0) {
            throw new RuntimeException('Active products still have blank SEO fields: titles='.$blankTitles.', descriptions='.$blankDescriptions);
        }

        return ['active_products' => $count, 'blank_titles' => $blankTitles, 'blank_descriptions' => $blankDescriptions];
    }

    public function rollback(array $plan): void
    {
        $this->checkPlan($plan);
        DB::transaction(function () use ($plan): void {
            foreach ($plan['operations'] as $operation) {
                $row = DB::table('products')->where('id', $operation['id'])->lockForUpdate()->first(self::FIELDS);
                if ($row === null || $row->slug !== $operation['slug'] || $row->name !== $operation['name'] || $row->status !== 'active' || $row->deleted_at !== null) {
                    throw new RuntimeException('Rollback identity mismatch for product '.$operation['id']);
                }
                foreach (['seo_title', 'seo_description', 'updated_at'] as $field) {
                    if ($row->$field !== $operation['after'][$field]) {
                        throw new RuntimeException('Product '.$operation['id'].' changed since apply; rollback refused.');
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
        $matched = 0;
        DB::table('products')->where('status', 'active')->whereNull('deleted_at')
            ->select(['id', 'name', 'seo_title', 'seo_description'])->orderBy('id')
            ->chunkById(500, function ($rows) use ($controller, $title, $description, &$matched): void {
                foreach ($rows as $row) {
                    $product = (new Product)->newFromBuilder((array) $row);
                    if ($title->invoke($controller, $product) !== $row->seo_title
                        || $description->invoke($controller, $product) !== $row->seo_description) {
                        throw new RuntimeException('Database/storefront metadata mismatch for product '.$row->id);
                    }
                    $matched++;
                }
            }, 'id', 'id');

        return $matched;
    }

    private function checkPlan(array $plan): void
    {
        if (($plan['repair'] ?? null) !== 'product-seo-database-20261031-v1') {
            throw new RuntimeException('Unexpected repair plan.');
        }
        foreach ($plan['operations'] as $operation) {
            if (array_keys($operation['after']) !== ['seo_title', 'seo_description', 'updated_at']
                || array_diff(array_keys($operation['before']), ['seo_title', 'seo_description', 'updated_at']) !== []) {
                throw new RuntimeException('Unexpected writable columns.');
            }
        }
    }
}
