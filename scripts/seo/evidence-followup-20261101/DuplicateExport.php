<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

final class ProductSeoDuplicateExport
{
    public const VERSION = 'product-seo-duplicate-review-20261101-v1';

    private static function plain(mixed $value, int $limit = 2000): string
    {
        return mb_substr(ProductSeoMetadata::plain($value), 0, $limit);
    }

    public function build(): array
    {
        $titles = [];
        foreach (DB::table('products')->where('status', 'active')->whereNull('deleted_at')->orderBy('id')->get(['id', 'seo_title']) as $row) {
            $titles[mb_strtolower(ProductSeoMetadata::plain($row->seo_title))][] = $row->id;
        }
        $groups = array_values(array_filter($titles, fn ($ids): bool => count($ids) > 1));
        $ids = array_merge([], ...$groups);
        $products = [];
        $variants = [];
        $identities = [];
        $variantCount = 0;
        foreach (array_chunk($ids, 100) as $chunk) {
            foreach (DB::table('products')->whereIn('id', $chunk)->orderBy('id')->get(['id', 'name', 'slug', 'external_source', 'external_id', 'status', 'deleted_at', 'published_at', 'seo_title', 'seo_description', 'description', 'short_description', 'updated_at']) as $object) {
                $row = (array) $object;
                foreach (['description' => 12000, 'short_description' => 3000] as $field => $limit) {
                    $value = ProductSeoMetadata::plain($row[$field]);
                    $row[$field] = mb_substr($value, 0, $limit);
                    $row[$field.'_sha256'] = hash('sha256', $value);
                    $row[$field.'_truncated'] = mb_strlen($value) > $limit;
                }
                $row['product_attributes'] = [];
                $row['variants'] = [];
                $products[$row['id']] = $row;
            }
            foreach (DB::table('product_attribute_value as pivot')->join('attribute_values as value', 'value.id', '=', 'pivot.attribute_value_id')->join('attributes as attribute', 'attribute.id', '=', 'value.attribute_id')
                ->whereIn('pivot.product_id', $chunk)->orderBy('pivot.product_id')->orderBy('value.id')->get(['pivot.product_id', 'attribute.id as attribute_id', 'attribute.name as attribute', 'value.id as value_id', 'value.value']) as $value) {
                $products[$value->product_id]['product_attributes'][] = ['attribute_id' => $value->attribute_id, 'attribute' => $value->attribute, 'value_id' => $value->value_id, 'value' => self::plain($value->value), 'value_truncated' => mb_strlen(ProductSeoMetadata::plain($value->value)) > 2000];
            }
            foreach (DB::table('product_variants')->whereIn('product_id', $chunk)->orderBy('id')->get(['id', 'product_id', 'sku', 'external_variant_id', 'status', 'deleted_at', 'is_default', 'price_net_amount', 'price_gross_amount', 'currency', 'vat_rate', 'stock_status']) as $object) {
                $row = (array) $object;
                $row['attributes'] = [];
                $variants[$row['id']] = $row;
            }
        }
        foreach (array_chunk(array_keys($variants), 500) as $chunk) {
            foreach (DB::table('product_variant_attribute_value as pivot')->join('attribute_values as value', 'value.id', '=', 'pivot.attribute_value_id')->join('attributes as attribute', 'attribute.id', '=', 'value.attribute_id')
                ->whereIn('pivot.product_variant_id', $chunk)->orderBy('pivot.product_variant_id')->orderBy('value.id')->get(['pivot.product_variant_id', 'attribute.name as attribute', 'value.value', 'value.id as value_id']) as $row) {
                $raw = ['attribute' => mb_strtolower(ProductSeoMetadata::plain($row->attribute)), 'value' => mb_strtolower(ProductSeoMetadata::plain($row->value))];
                $identities[$row->product_variant_id][] = $raw;
                $variants[$row->product_variant_id]['attributes'][] = ['attribute' => $row->attribute, 'value_id' => $row->value_id, 'value' => self::plain($row->value), 'value_truncated' => mb_strlen(ProductSeoMetadata::plain($row->value)) > 2000];
            }
        }
        $active = [];
        $incomplete = [];
        foreach ($variants as $id => $row) {
            $variantCount++;
            $products[$row['product_id']]['variants'][] = $row;
            if ($row['status'] !== 'active' || $row['deleted_at'] !== null) {
                continue;
            }
            if (ProductSeoMetadata::plain($row['sku']) === '') {
                $incomplete[$row['product_id']] = true;
            }
            $attributes = $identities[$id] ?? [];
            usort($attributes, fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $active[$row['product_id']][] = ['sku' => mb_strtolower(ProductSeoMetadata::plain($row['sku'])), 'attributes' => $attributes];
        }
        $fingerprints = [];
        $optionPrints = [];
        foreach ($products as $id => &$row) {
            $items = $active[$id] ?? [];
            usort($items, fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $row['active_variant_count'] = count($items);
            $row['active_variant_identity_sha256'] = $items === [] || isset($incomplete[$id]) ? null : hash('sha256', json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $options = array_column($items, 'attributes');
            usort($options, fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $row['active_variant_options_sha256'] = $options === [] || in_array([], $options, true) ? null : hash('sha256', json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $fingerprints[$id] = $row['active_variant_identity_sha256'];
            $optionPrints[$id] = $row['active_variant_options_sha256'];
        }
        unset($row);
        $review = [];
        foreach ($groups as $members) {
            $signatures = array_values(array_unique(array_map(fn ($id) => $fingerprints[$id], $members)));
            $optionSignals = array_values(array_unique(array_map(fn ($id) => $optionPrints[$id], $members)));
            $review[] = ['product_ids' => $members, 'title' => $products[$members[0]]['seo_title'],
                'identity_signal' => in_array(null, $signatures, true) ? 'insufficient_active_variant_identity' : (count($signatures) === 1 ? 'same_active_variant_signature_candidate' : 'different_active_variant_signatures'),
                'option_signal' => ! in_array(null, $optionSignals, true) && count($optionSignals) === 1 ? 'same_active_options_review_candidate' : 'options_differ_or_incomplete',
                'action' => 'manual_review_required'];
        }

        return ['version' => self::VERSION, 'exported_at' => now()->toIso8601String(), 'database_writes' => false,
            'summary' => ['duplicate_title_groups' => count($groups), 'exported_products' => count($products), 'exported_variants' => $variantCount,
                'signature_match_candidate_groups' => count(array_filter($review, fn ($g): bool => $g['identity_signal'] === 'same_active_variant_signature_candidate')),
                'matching_option_groups' => count(array_filter($review, fn ($g): bool => $g['option_signal'] === 'same_active_options_review_candidate'))],
            'limitations' => ['Identity and option signatures are review signals, not proof of duplication or distinction.', 'Price and stock are exported but excluded from identity signatures.', 'No products, variants, prices or redirects are changed.', 'Order/cart/media reference eligibility must be audited before any consolidation.'],
            'groups' => $review, 'products' => array_values($products)];
    }
}
