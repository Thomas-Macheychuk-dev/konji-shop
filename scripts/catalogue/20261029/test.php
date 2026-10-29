<?php

declare(strict_types=1);

define('CATALOGUE_REPAIR_FUNCTIONS_ONLY', true);
require __DIR__.'/run.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    demand($condition, 'Test failed: '.$message);
    $checks++;
}
function rejects(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        check(true, $message);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}
function category(int $id, ?int $parent, string $name): array
{
    return ['id' => $id, 'parent_id' => $parent, 'name' => $name, 'slug' => 'category-'.$id, 'status' => 'active', 'deleted_at' => null];
}
$source = [
    'categories' => [category(23, 560, 'Shared accessories'), category(65, null, 'Wigs'), category(121, 23, 'Wig accessories'), category(560, null, 'Clothing')],
    'products' => [
        ['id' => 1, 'name' => 'Wig stand', 'slug' => 'wig-stand', 'external_source' => 'peruka', 'external_id' => 'one', 'status' => 'active', 'deleted_at' => null],
        ['id' => 2, 'name' => 'Tunic', 'slug' => 'tunic', 'external_source' => 'eldan', 'external_id' => 'two', 'status' => 'active', 'deleted_at' => null],
    ],
    'assignments' => [
        ['product_id' => 1, 'category_id' => 23, 'is_primary' => 0],
        ['product_id' => 1, 'category_id' => 121, 'is_primary' => 1],
        ['product_id' => 1, 'category_id' => 560, 'is_primary' => 0],
    ],
];
$plan = [
    'roots' => [['ref' => 65], ['ref' => 560]],
    'create_categories' => [['ref' => -10, 'parent_ref' => 560, 'name' => 'Tunics', 'slug' => 'tunics']],
    'update_categories' => [['id' => 121, 'slug' => 'category-121', 'parent_ref' => 65, 'name' => 'Wig accessories', 'status' => 'active']],
    'product_primary_overrides' => [['product_id' => 2, 'name' => 'Tunic', 'external_source' => 'eldan', 'external_id' => 'two', 'category_ref' => -10]],
    'retire_category_ids' => [],
];
[$categories, $assignments] = plannedState($source, $plan);
check(count($assignments) === 4, 'only repaired primary/ancestor pairs survive');
check(! array_filter($assignments, fn ($r): bool => $r['product_id'] === 1 && in_array($r['category_id'], [23, 560], true)), 'old explicit clothing ancestors are removed');
check(count(array_filter($assignments, fn ($r): bool => $r['is_primary'] === 1)) === 2, 'missing primary restored');
check($source['products'][0]['slug'] === 'wig-stand', 'planning leaves product identity untouched');
$bad = $plan;
$bad['update_categories'][0]['parent_ref'] = 121;
rejects(fn () => plannedState($source, $bad), 'self cycle');
$bad = $plan;
$bad['update_categories'][0]['parent_ref'] = 999;
rejects(fn () => plannedState($source, $bad), 'missing parent');
$bad = $plan;
$bad['product_primary_overrides'] = [];
rejects(fn () => plannedState($source, $bad), 'uncategorized product without reviewed override');
$bad = $plan;
$bad['product_primary_overrides'][0]['name'] = 'Different product';
rejects(fn () => plannedState($source, $bad), 'wrong product identity');
$bad = $plan;
$bad['create_categories'][0]['slug'] = 'category-65';
rejects(fn () => plannedState($source, $bad), 'duplicate slugs');
$bad = $plan;
$bad['update_categories'][0]['status'] = 'archived';
rejects(fn () => plannedState($source, $bad), 'inactive primary path');
$state = ['categories' => $source['categories'], 'products' => $source['products'], 'category_product' => array_reverse($source['assignments'])];
assertBaseline($state, $source);
check(true, 'baseline normalization ignores assignment row order');
foreach (['categories', 'products', 'category_product'] as $table) {
    $bad = $state;
    $field = $table === 'category_product' ? 'is_primary' : 'name';
    $bad[$table][0][$field] = $table === 'category_product' ? 1 : 'changed';
    rejects(fn () => assertBaseline($bad, $source), 'snapshot drift '.$table);
}
$options = getopt('', ['source:', 'plan:', 'expected:']);
if (isset($options['source'], $options['plan'], $options['expected'])) {
    $fullSource = jsonFile($options['source']);
    $fullPlan = jsonFile($options['plan']);
    [$fullCategories, $fullAssignments] = plannedState($fullSource, $fullPlan);
    check($fullAssignments === jsonFile($options['expected']), 'PHP independently matches the complete Python-simulated assignment set');
    check(count($fullAssignments) > 45000, 'full snapshot exercised');
    echo 'FULL_SNAPSHOT_PRODUCTS='.count($fullSource['products'])."\n";
    echo 'FULL_SNAPSHOT_ASSIGNMENTS='.count($fullAssignments)."\n";
}
echo 'TEST_CHECKS='.$checks."\nTESTS=PASS\n";
