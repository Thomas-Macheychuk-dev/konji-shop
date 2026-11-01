<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

final class ProductSourceCleanup
{
    private const REPAIR = 'product-source-cleanup-20261101-v1';

    private const CONTENT = ['description', 'short_description'];

    private const FIELDS = ['id', 'name', 'slug', 'external_source', 'external_id', 'status', 'deleted_at', 'seo_title', 'seo_description', 'description', 'short_description', 'updated_at'];

    public function __construct(private readonly array $manifest)
    {
        if (($manifest['repair'] ?? null) !== self::REPAIR || empty($manifest['entries'])) {
            throw new RuntimeException('Invalid source cleanup manifest.');
        }
        $ids = array_column($manifest['entries'], 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Duplicate product IDs.');
        }
        $links = [];
        foreach ($manifest['detach_attribute_values'] ?? [] as $link) {
            $key = $link['product_id'].':'.$link['attribute_value_id'];
            if (! in_array($link['product_id'], $ids, true) || isset($links[$key])) {
                throw new RuntimeException('Invalid attribute detach targets.');
            }
            $links[$key] = true;
        }
        foreach ($manifest['entries'] as $entry) {
            if (empty($entry['after']) || array_diff(array_keys($entry['after']), self::CONTENT)) {
                throw new RuntimeException('Only source description fields are writable.');
            }
            foreach ($entry['after'] as $field => $value) {
                if (! is_string($value) || ProductSeoMetadata::plain($value) === '' || mb_strlen($value) > 12000
                    || preg_match('/<(?!\/?(?:p|ul|li|strong)>)/i', $value)
                    || ($field === 'short_description' && ProductSeoMetadata::plain($value) !== $value)) {
                    throw new RuntimeException('Invalid reviewed source copy.');
                }
            }
        }
    }

    private function identity(array $row, array $entry): void
    {
        if ($row['status'] !== 'active' || $row['deleted_at'] !== null) {
            throw new RuntimeException('Product is no longer active: '.$entry['id']);
        }
        foreach (['name', 'slug', 'external_source', 'external_id', 'seo_title', 'seo_description'] as $field) {
            if ($row[$field] !== $entry['expected'][$field]) {
                throw new RuntimeException('Identity or SEO changed: '.$entry['id'].' '.$field);
            }
        }
    }

    private function original(array $row, array $entry): void
    {
        $this->identity($row, $entry);
        foreach (['description' => 12000, 'short_description' => 3000] as $field => $limit) {
            if (! hash_equals($entry['source_'.$field.'_sha256'], hash('sha256', mb_substr(ProductSeoMetadata::plain($row[$field]), 0, $limit)))) {
                throw new RuntimeException('Source evidence changed: '.$entry['id'].' '.$field);
            }
        }
    }

    private static function rowsHash(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function scan(bool $lock = false): array
    {
        $rows = [];
        $operations = [];
        $timestamp = now()->format('Y-m-d H:i:s');
        // Stable order avoids deadlocks between overlapping content repairs.
        $entries = $this->manifest['entries'];
        usort($entries, fn ($a, $b): int => $a['id'] <=> $b['id']);
        foreach ($entries as $entry) {
            $query = DB::table('products')->where('id', $entry['id']);
            if ($lock) {
                $query->lockForUpdate();
            }
            $object = $query->first(self::FIELDS);
            if ($object === null) {
                throw new RuntimeException('Product missing: '.$entry['id']);
            }
            $row = (array) $object;
            $this->original($row, $entry);
            $rows[] = $row;
            $before = array_intersect_key($row, $entry['after']) + ['updated_at' => $row['updated_at']];
            $operations[] = ['id' => $row['id'], 'name' => $row['name'], 'slug' => $row['slug'], 'before' => $before, 'after' => $entry['after'] + ['updated_at' => $timestamp]];
        }

        $detaches = $this->attributeLinks($lock);

        return ['repair' => self::REPAIR, 'manifest_sha256' => self::rowsHash($this->manifest), 'source_sha256' => self::rowsHash(['products' => $rows, 'attribute_detaches' => $detaches]), 'source_rows' => $rows, 'attribute_detaches' => $detaches, 'operations' => $operations,
            'stats' => ['active_products' => $this->metadataSummary()['active_products'], 'changed_products' => count($operations),
                'description_updates' => count(array_filter($operations, fn ($o): bool => array_key_exists('description', $o['after']))),
                'short_description_updates' => count(array_filter($operations, fn ($o): bool => array_key_exists('short_description', $o['after']))), 'seo_updates' => 0, 'attribute_detaches' => count($detaches)],
            'reasons' => ['reviewed_source_conflict' => count($operations)]];
    }

    public function plan(): array
    {
        return $this->scan();
    }

    private function checkPlan(array $plan): void
    {
        if (($plan['repair'] ?? null) !== self::REPAIR
            || ! hash_equals(self::rowsHash($this->manifest), $plan['manifest_sha256'] ?? '')
            || ! hash_equals(self::rowsHash(['products' => $plan['source_rows'] ?? [], 'attribute_detaches' => $plan['attribute_detaches'] ?? []]), $plan['source_sha256'] ?? '')) {
            throw new RuntimeException('Plan identity or source checksum mismatch.');
        }
        if (count($plan['attribute_detaches'] ?? []) !== count($this->manifest['detach_attribute_values'] ?? [])) {
            throw new RuntimeException('Attribute detach set changed.');
        }
        foreach ($plan['attribute_detaches'] ?? [] as $index => $link) {
            $expected = $this->manifest['detach_attribute_values'][$index];
            if ($link['expected'] !== $expected || $link['before']['product_id'] !== $expected['product_id']
                || $link['before']['attribute_value_id'] !== $expected['attribute_value_id']
                || ! $this->attributeValueMatches($link['value'], $expected)) {
                throw new RuntimeException('Tampered attribute backup.');
            }
        }
        $entries = array_column($this->manifest['entries'], null, 'id');
        $ids = array_column($plan['operations'], 'id');
        $expectedIds = array_keys($entries);
        sort($expectedIds);
        if ($ids !== $expectedIds || array_column($plan['source_rows'], 'id') !== $ids) {
            throw new RuntimeException('Plan target set changed.');
        }
        foreach ($plan['operations'] as $index => $operation) {
            $entry = $entries[$operation['id']];
            $row = $plan['source_rows'][$index];
            $this->original($row, $entry);
            $after = $operation['after'];
            unset($after['updated_at']);
            if ($after !== $entry['after'] || ! is_string($operation['after']['updated_at'] ?? null)
                || $operation['before'] !== array_intersect_key($row, $entry['after']) + ['updated_at' => $row['updated_at']]
                || $operation['name'] !== $row['name'] || $operation['slug'] !== $row['slug']) {
                throw new RuntimeException('Tampered source operation or backup.');
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
            foreach ($live['operations'] as $index => $operation) {
                if ($operation['before'] !== $plan['operations'][$index]['before']) {
                    throw new RuntimeException('Backup changed.');
                }
                $after = $operation['after'];
                $after['updated_at'] = $plan['operations'][$index]['after']['updated_at'];
                if (DB::table('products')->where('id', $operation['id'])->update($after) !== 1) {
                    throw new RuntimeException('Source update failed.');
                }
            }
            foreach ($live['attribute_detaches'] as $link) {
                if (DB::table('product_attribute_value')->where('product_id', $link['expected']['product_id'])->where('attribute_value_id', $link['expected']['attribute_value_id'])->delete() !== 1) {
                    throw new RuntimeException('Attribute detach failed.');
                }
            }
        });
    }

    private function appliedRow(array $row, array $plan, int $index): void
    {
        $operation = $plan['operations'][$index];
        $entry = collect($this->manifest['entries'])->firstWhere('id', $operation['id']);
        $this->identity($row, $entry);
        $expected = array_replace($plan['source_rows'][$index], $operation['after']);
        foreach (self::FIELDS as $field) {
            if ($row[$field] !== $expected[$field]) {
                throw new RuntimeException('Later edit or verification mismatch: '.$operation['id'].' '.$field);
            }
        }
    }

    private function attributeValueMatches(array $value, array $expected): bool
    {
        return $value['id'] === $expected['attribute_value_id'] && $value['attribute_id'] === $expected['attribute_id']
            && ProductSeoMetadata::plain($value['value']) === $expected['expected_value'];
    }

    private function attributeLinks(bool $lock = false, bool $applied = false): array
    {
        $links = [];
        foreach ($this->manifest['detach_attribute_values'] ?? [] as $expected) {
            $valueQuery = DB::table('attribute_values')->where('id', $expected['attribute_value_id']);
            $pivotQuery = DB::table('product_attribute_value')->where('product_id', $expected['product_id'])->where('attribute_value_id', $expected['attribute_value_id']);
            if ($lock) {
                $valueQuery->lockForUpdate();
                $pivotQuery->lockForUpdate();
            }
            $value = $valueQuery->first(['id', 'attribute_id', 'value']);
            $pivot = $pivotQuery->get();
            if ($value === null || ! $this->attributeValueMatches((array) $value, $expected)
                || $pivot->count() !== ($applied ? 0 : 1)) {
                throw new RuntimeException('Attribute evidence or link changed.');
            }
            if (! $applied) {
                $links[] = ['expected' => $expected, 'value' => (array) $value, 'before' => (array) $pivot->first()];
            }
        }

        return $links;
    }

    private function metadataSummary(): array
    {
        $count = 0;
        DB::table('products')->where('status', 'active')->whereNull('deleted_at')->select(['id', 'seo_title', 'seo_description'])->orderBy('id')
            ->chunkById(500, function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    if (ProductSeoMetadata::placeholder(ProductSeoMetadata::plain($row->seo_title)) || ProductSeoMetadata::placeholder(ProductSeoMetadata::plain($row->seo_description))) {
                        throw new RuntimeException('Active product metadata is missing.');
                    }
                    $count++;
                }
            }, 'id', 'id');

        return ['active_products' => $count, 'missing_metadata' => 0];
    }

    public function verify(array $plan): array
    {
        $this->checkPlan($plan);
        foreach ($plan['operations'] as $index => $operation) {
            $row = DB::table('products')->where('id', $operation['id'])->first(self::FIELDS);
            if ($row === null) {
                throw new RuntimeException('Verified product missing.');
            }
            $this->appliedRow((array) $row, $plan, $index);
        }

        $this->attributeLinks(applied: true);

        return $this->metadataSummary() + ['verified_source_products' => count($plan['operations']), 'detached_attribute_links' => count($plan['attribute_detaches'])];
    }

    public function rollback(array $plan): void
    {
        $this->checkPlan($plan);
        DB::transaction(function () use ($plan): void {
            foreach ($plan['operations'] as $index => $operation) {
                $row = DB::table('products')->where('id', $operation['id'])->lockForUpdate()->first(self::FIELDS);
                if ($row === null) {
                    throw new RuntimeException('Rollback product missing.');
                }
                $this->appliedRow((array) $row, $plan, $index);
            }
            $this->attributeLinks(lock: true, applied: true);
            foreach ($plan['operations'] as $operation) {
                DB::table('products')->where('id', $operation['id'])->update($operation['before']);
            }
            foreach ($plan['attribute_detaches'] as $link) {
                DB::table('product_attribute_value')->insert($link['before']);
            }
        });
    }

    public static function invalidateCache(): void
    {
        ProductSeoEditorialRepair::invalidateCache();
    }

    public function verifyRenderedMetadata(): int
    {
        $entries = array_map(static function ($entry): array {
            $entry['after'] = array_intersect_key($entry['expected'], array_flip(['seo_title', 'seo_description']));

            return $entry;
        }, $this->manifest['entries']);

        return (new ProductSeoEditorialRepair(['repair' => 'product-seo-editorial-20261031-v1', 'entries' => $entries]))->verifyRenderedMetadata();
    }
}
