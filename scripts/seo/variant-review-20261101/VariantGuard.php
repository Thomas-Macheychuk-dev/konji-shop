<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/** Keep variant-specific SEO copy tied to the reviewed complete configuration. */
final class ProductSeoVariantEditorialRepair
{
    private ProductSeoEditorialRepair $repair;

    public function __construct(private readonly array $manifest)
    {
        $this->repair = new ProductSeoEditorialRepair($manifest);
        foreach ($manifest['entries'] as $entry) {
            if (empty($entry['evidence']['variant_snapshot'])) {
                throw new RuntimeException('Missing complete variant evidence.');
            }
        }
    }

    private function assertVariants(bool $lock = false): void
    {
        $ids = array_column($this->manifest['entries'], 'id');
        $query = DB::table('product_variants')->whereIn('product_id', $ids)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $variants = [];
        foreach ($query->get(['id', 'product_id', 'sku', 'external_variant_id', 'status', 'deleted_at']) as $object) {
            $row = (array) $object;
            $row['attributes'] = [];
            $variants[$row['id']] = $row;
        }
        $attributes = DB::table('product_variant_attribute_value as pivot')
            ->join('attribute_values as value', 'value.id', '=', 'pivot.attribute_value_id')
            ->join('attributes as attribute', 'attribute.id', '=', 'value.attribute_id')
            ->whereIn('pivot.product_variant_id', array_keys($variants))
            ->orderBy('pivot.product_variant_id')->orderBy('value.id');
        if ($lock) {
            $attributes->lockForUpdate();
        }
        foreach ($attributes->get(['pivot.product_variant_id', 'attribute.name as attribute', 'value.id as value_id', 'value.value']) as $row) {
            $variants[$row->product_variant_id]['attributes'][] = ['attribute' => $row->attribute, 'value_id' => $row->value_id, 'value' => ProductSeoMetadata::plain($row->value)];
        }
        $products = [];
        foreach ($variants as $row) {
            $id = $row['product_id'];
            unset($row['product_id']);
            $products[$id][] = $row;
        }
        foreach ($this->manifest['entries'] as $entry) {
            if (($products[$entry['id']] ?? []) !== $entry['evidence']['variant_snapshot']) {
                throw new RuntimeException('Reviewed variant configuration changed: '.$entry['id']);
            }
        }
    }

    public function plan(): array
    {
        $this->assertVariants();

        return $this->repair->plan();
    }

    public function apply(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $this->assertVariants(true);
            $this->repair->apply($plan);
        });
    }

    public function verify(array $plan): array
    {
        $this->assertVariants();

        return $this->repair->verify($plan);
    }

    public function verifyRenderedMetadata(): int
    {
        return $this->repair->verifyRenderedMetadata();
    }

    public function rollback(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $this->assertVariants(true);
            $this->repair->rollback($plan);
        });
    }
}
