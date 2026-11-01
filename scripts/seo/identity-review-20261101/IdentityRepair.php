<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

final class ProductSeoIdentityRepair
{
    private ProductSeoVariantEditorialRepair $before;

    private ProductSeoVariantEditorialRepair $after;

    private array $afterManifest;

    public function __construct(private readonly array $manifest)
    {
        if (($manifest['batch'] ?? '') !== 'product-seo-identity-review-20261101-v1') {
            throw new RuntimeException('Unexpected identity review batch.');
        }
        $this->before = new ProductSeoVariantEditorialRepair($manifest);
        $this->afterManifest = $manifest;
        foreach ($this->afterManifest['entries'] as &$entry) {
            if (isset($entry['after_name'])) {
                foreach (['after_name', 'after_image_caption'] as $key) {
                    $value = $entry[$key] ?? '';
                    if ($value === '' || ProductSeoMetadata::plain($value) !== $value || mb_strlen($value) > 255) {
                        throw new RuntimeException('Invalid reviewed name/caption.');
                    }
                }
                $entry['expected']['name'] = $entry['after_name'];
            }
        }
        unset($entry);
        $this->after = new ProductSeoVariantEditorialRepair($this->afterManifest);
    }

    private static function hash(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function media(bool $lock = false): array
    {
        $query = DB::table('product_images')->whereIn('product_id', array_column($this->manifest['entries'], 'id'))->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = array_map(fn ($r): array => (array) $r, $query->get()->all());
        foreach ($this->manifest['entries'] as $entry) {
            $urls = array_column(array_filter($rows, fn ($r): bool => $r['product_id'] === $entry['id']), 'source_url');
            if (array_diff($entry['evidence']['media_samples'], $urls) !== []) {
                throw new RuntimeException('Reviewed media identity changed: '.$entry['id']);
            }
        }
        $attributes = DB::table('product_attribute_value as pivot')->join('attribute_values as value', 'value.id', '=', 'pivot.attribute_value_id')->join('attributes as attribute', 'attribute.id', '=', 'value.attribute_id')
            ->whereIn('pivot.product_id', array_column($this->manifest['entries'], 'id'))->orderBy('pivot.product_id')->orderBy('value.id');
        if ($lock) {
            $attributes->lockForUpdate();
        }
        $values = [];
        foreach ($attributes->get(['pivot.product_id', 'attribute.name as attribute', 'value.id as value_id', 'value.value']) as $r) {
            $values[$r->product_id][] = ['attribute' => $r->attribute, 'value_id' => $r->value_id, 'value' => ProductSeoMetadata::plain($r->value)];
        }
        foreach ($this->manifest['entries'] as $entry) {
            if (($values[$entry['id']] ?? []) !== $entry['evidence']['producer_attributes']) {
                throw new RuntimeException('Reviewed product attributes changed: '.$entry['id']);
            }
        }

        return $rows;
    }

    private function afterPlan(array $plan): array
    {
        $plan['manifest_sha256'] = self::hash($this->afterManifest);
        foreach ($plan['operations'] as $index => &$operation) {
            $operation['name'] = $this->afterManifest['entries'][$index]['expected']['name'];
        }
        unset($operation);

        return $plan;
    }

    private function afterMedia(array $plan): array
    {
        $rows = $plan['media_before'];
        $operations = array_column($plan['media_operations'], null, 'id');
        foreach ($rows as &$row) {
            if (isset($operations[$row['id']])) {
                $row = array_replace($row, $operations[$row['id']]['after']);
            }
        }
        unset($row);

        return $rows;
    }

    private function mediaOperations(array $media, string $timestamp): array
    {
        $entries = array_column($this->manifest['entries'], null, 'id');
        $operations = [];
        foreach ($media as $row) {
            $entry = $entries[$row['product_id']];
            if (! isset($entry['after_name'])) {
                continue;
            }
            $changes = [];
            foreach (['alt_text', 'title'] as $field) {
                if (is_string($row[$field]) && str_contains($row[$field], $entry['expected']['name'])) {
                    $changes[$field] = str_replace($entry['expected']['name'], $entry['after_image_caption'], $row[$field]);
                }
            }
            if ($changes !== []) {
                $changes['updated_at'] = $timestamp;
                $operations[] = ['id' => $row['id'], 'before' => array_intersect_key($row, $changes), 'after' => $changes];
            }
        }
        return $operations;
    }

    private function checkMediaPlan(array $plan): void
    {
        if ($this->mediaOperations($plan['media_before'], $plan['operations'][0]['after']['updated_at']) !== $plan['media_operations']) {
            throw new RuntimeException('Image operations differ from the reviewed name correction.');
        }
    }

    public function plan(): array
    {
        $media = $this->media();
        $plan = $this->before->plan();
        $operations = $this->mediaOperations($media, $plan['operations'][0]['after']['updated_at']);
        $plan['media_before'] = $media;
        $plan['media_operations'] = $operations;
        $plan['stats']['name_updates'] = count(array_filter($this->manifest['entries'], fn ($e): bool => isset($e['after_name'])));
        $plan['stats']['image_text_updates'] = count($operations);

        return $plan;
    }

    public function apply(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $this->checkMediaPlan($plan);
            if ($this->media(true) !== $plan['media_before']) {
                throw new RuntimeException('Image state changed since dry-run.');
            }
            $this->before->apply($plan);
            foreach ($this->manifest['entries'] as $entry) {
                if (isset($entry['after_name'])) {
                    DB::table('products')->where('id', $entry['id'])->update(['name' => $entry['after_name']]);
                }
            }
            foreach ($plan['media_operations'] as $operation) {
                DB::table('product_images')->where('id', $operation['id'])->update($operation['after']);
            }
            $this->verify($plan);
        });
    }

    public function verify(array $plan): array
    {
        $this->checkMediaPlan($plan);
        if ($this->media() !== $this->afterMedia($plan)) {
            throw new RuntimeException('Image verification differs or later edits exist.');
        }

        return $this->after->verify($this->afterPlan($plan)) + ['verified_name_corrections' => 3, 'verified_image_text_updates' => count($plan['media_operations'])];
    }

    public function rollback(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $this->checkMediaPlan($plan);
            if ($this->media(true) !== $this->afterMedia($plan)) {
                throw new RuntimeException('Later image edits detected; rollback refused.');
            }
            $this->after->rollback($this->afterPlan($plan));
            foreach ($this->manifest['entries'] as $entry) {
                if (isset($entry['after_name'])) {
                    DB::table('products')->where('id', $entry['id'])->update(['name' => $entry['expected']['name']]);
                }
            }
            foreach ($plan['media_operations'] as $operation) {
                DB::table('product_images')->where('id', $operation['id'])->update($operation['before']);
            }
            if ($this->media() !== $plan['media_before']) {
                throw new RuntimeException('Image rollback verification failed.');
            }
        });
    }

    public function verifyRenderedMetadata(): int
    {
        return $this->before->verifyRenderedMetadata();
    }

    public static function invalidateCache(): void
    {
        ProductSeoEditorialRepair::invalidateCache();
        app(\App\Services\Storefront\StorefrontCache::class)->bump(\App\Services\Storefront\StorefrontCache::NAMESPACE_NAVIGATION);
    }
}
