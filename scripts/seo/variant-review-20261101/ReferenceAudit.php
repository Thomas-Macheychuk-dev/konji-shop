<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Counts only: never export orders, customers, cart contents or arbitrary payloads. */
final class ProductSeoReferenceAudit
{
    public function build(array $products): array
    {
        $ids = array_column($products, 'id');
        $variantOwners = [];
        $result = [];
        foreach ($products as $product) {
            $result[$product['id']] = ['product_id' => $product['id'], 'references' => []];
            foreach ($product['variants'] as $variant) {
                $variantOwners[$variant['id']] = $product['id'];
            }
        }
        $inventory = [];
        $limitations = ['Counts include soft-deleted rows. Product and variant counts can overlap; do not add them as distinct records.', 'Zero counts do not establish merge eligibility. JSON/text payloads, URL links, application business rules and external/nginx mappings still require review.', 'No canonical winner, merge, archive, redirect or automatic eligibility decision is produced.'];
        $count = function (string $table, string $column, string $target, string $method, ?array $morph = null) use ($ids, $variantOwners, &$result, &$inventory): void {
            $keys = $target === 'products' ? $ids : array_keys($variantOwners);
            $query = DB::table($table)->whereIn($column, $keys);
            if ($morph !== null) {
                $query->whereIn($morph['column'], $morph['types']);
            }
            $inventory[] = ['table' => $table, 'column' => $column, 'target' => $target, 'discovery' => $method, 'morph' => $morph];
            foreach ($query->select($column.' as reference_id')->selectRaw('COUNT(*) as reference_count')->groupBy($column)->get() as $row) {
                $id = $target === 'products' ? $row->reference_id : $variantOwners[$row->reference_id];
                $key = $table.'.'.$column.($morph === null ? '' : ':'.$target);
                $result[$id]['references'][$key] = ($result[$id]['references'][$key] ?? 0) + (int) $row->reference_count;
            }
        };
        foreach (Schema::getTables() as $tableInfo) {
            $table = $tableInfo['name'];
            $columns = array_column(Schema::getColumns($table), 'name');
            $links = [];
            foreach (['product_id' => 'products', 'product_variant_id' => 'product_variants', 'variant_id' => 'product_variants'] as $column => $target) {
                if (in_array($column, $columns, true)) {
                    $links[$column] = [$target, 'column_name'];
                }
            }
            foreach (Schema::getForeignKeys($table) as $foreign) {
                if (! in_array($foreign['foreign_table'], ['products', 'product_variants'], true)) {
                    continue;
                }
                if (count($foreign['columns']) !== 1 || $foreign['foreign_columns'] !== ['id']) {
                    $limitations[] = 'Unresolved composite/non-ID reference on '.$table;
                    continue;
                }
                $links[$foreign['columns'][0]] = [$foreign['foreign_table'], 'foreign_key'];
            }
            foreach ($links as $column => [$target, $method]) {
                $count($table, $column, $target, $method);
            }
            foreach ($columns as $column) {
                if (! str_ends_with($column, '_type')) {
                    continue;
                }
                $idColumn = substr($column, 0, -5).'_id';
                if (! in_array($idColumn, $columns, true) || isset($links[$idColumn])) {
                    continue;
                }
                foreach (['products' => Product::class, 'product_variants' => ProductVariant::class] as $target => $class) {
                    $types = [$class, (new $class)->getMorphClass()];
                    foreach (Relation::morphMap() as $alias => $mapped) {
                        if ($mapped === $class) {
                            $types[] = $alias;
                        }
                    }
                    $count($table, $idColumn, $target, 'polymorphic_type', ['column' => $column, 'types' => array_values(array_unique($types))]);
                }
            }
        }
        foreach (DB::table('products')->whereIn('id', $ids)->get(['id', 'default_image_type', 'default_image_id']) as $row) {
            $result[$row->id]['default_image'] = ['type' => $row->default_image_type, 'id' => $row->default_image_id];
        }
        foreach ($result as &$row) {
            ksort($row['references']);
        }
        unset($row);

        return ['references' => array_values($result), 'reference_inventory' => $inventory, 'reference_limitations' => $limitations];
    }
}
