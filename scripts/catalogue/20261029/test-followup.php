<?php

declare(strict_types=1);

define('CATALOGUE_FOLLOWUP_FUNCTIONS_ONLY', true);
require __DIR__.'/followup.php';

$options = getopt('', ['source:', 'plan:', 'operations:']);
$source = jsonFile($options['source']);
$plan = jsonFile($options['plan']);
$operations = jsonFile($options['operations'])['operations'];
[$categories, $memberships] = plannedState($source, $plan);
$state = ['categories' => array_values($categories), 'products' => $source['products'], 'category_product' => $memberships];
$replacement = followupAssignments($state, $operations, []);
$ids = array_column($operations, 'product_id');
$after = array_values(array_filter($memberships, fn ($r): bool => ! in_array($r['product_id'], $ids, true)));
array_push($after, ...$replacement);
demand(count($ids) === 18, 'Expected 18 reviewed products');
demand(count($memberships) === 51616 && count($after) === 51614, 'Unexpected before/after membership counts');
$primaries = [];
foreach ($after as $row) {
    if ($row['is_primary']) {
        demand(! isset($primaries[$row['product_id']]), 'Duplicate primary');
        $primaries[$row['product_id']] = $row['category_id'];
    }
    activeChain($row['category_id'], $categories);
}
demand(count($primaries) === 14627, 'Product coverage changed');
$walkerCount = 0;
foreach ($operations as $op) {
    demand($primaries[$op['product_id']] === $op['new_ref'], 'Incorrect explicit correction');
    if ($op['new_ref'] === 20) {
        $walkerCount++;
    }
}
demand($walkerCount === 14, 'Expected fourteen complete walking aids');
demand($primaries[10457] === -13 && $primaries[10458] === -13, 'Actual spare wheels must remain accessories');
demand($primaries[10419] === 6 && $primaries[10460] === 6, 'Wheelchair footrest/brake must remain accessories');
demand($primaries[10273] === 1012, 'Drinking aid must not be medicine storage');
foreach ([10422, 10445, 10446] as $id) {
    demand($primaries[$id] === 486, 'Walking-stick/crutch tips must not be wheelchair accessories');
}
$bad = $state;
$bad['products'][0]['name'] = 'Unrelated change';
// An affected product identity drift must be rejected.
foreach ($bad['products'] as &$row) {
    if ($row['id'] === 9944) {
        $row['name'] = 'Different product';
    }
}
unset($row);
$rejected = false;
try {
    followupAssignments($bad, $operations, []);
} catch (RuntimeException) {
    $rejected = true;
}
demand($rejected, 'Identity drift was not rejected');
// Production IDs are resolved from the receipt, never assumed to start at 1218.
$refs = [];
foreach ($plan['create_categories'] as $index => $category) {
    $refs[$category['ref']] = 90000 + $index;
}
$mappedState = $state;
foreach ($mappedState['categories'] as &$category) {
    $category['id'] = $refs[$category['id']] ?? $category['id'];
    $category['parent_id'] = $category['parent_id'] === null ? null : ($refs[$category['parent_id']] ?? $category['parent_id']);
}
unset($category);
foreach ($mappedState['category_product'] as &$row) {
    $row['category_id'] = $refs[$row['category_id']] ?? $row['category_id'];
}
unset($row);
$mappedReplacement = followupAssignments($mappedState, $operations, $refs);
$expectedMapped = $replacement;
foreach ($expectedMapped as &$row) {
    $row['category_id'] = $refs[$row['category_id']] ?? $row['category_id'];
}
unset($row);
$sort = fn ($a, $b): int => [$a['product_id'], $a['category_id']] <=> [$b['product_id'], $b['category_id']];
usort($mappedReplacement, $sort);
usort($expectedMapped, $sort);
demand($mappedReplacement === $expectedMapped, 'Receipt reference resolution differs from simulation');
echo "FOLLOWUP_PRODUCTS=18\nWALKING_AIDS_CORRECTED=14\nALL_PRODUCT_PRIMARIES=14627\nEXPECTED_ASSIGNMENTS=51614\nFOLLOWUP_TESTS=PASS\n";
