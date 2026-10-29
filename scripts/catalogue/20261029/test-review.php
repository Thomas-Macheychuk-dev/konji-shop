<?php

declare(strict_types=1);

define('CATALOGUE_REVIEW_FUNCTIONS_ONLY', true);
require __DIR__.'/review.php';
define('CATALOGUE_FOLLOWUP_FUNCTIONS_ONLY', true);
require __DIR__.'/followup.php';

$options = getopt('', ['source:', 'plan:', 'original-plan:', 'followup:']);
$source = jsonFile($options['source']);
$originalPlan = jsonFile($options['original-plan']);
[$categories, $memberships] = plannedState($source, $originalPlan);
$state = ['categories' => array_values($categories), 'products' => $source['products'], 'category_product' => $memberships];
$followup = jsonFile($options['followup'])['operations'];
$followupIds = array_column($followup, 'product_id');
$state['category_product'] = array_values(array_filter($memberships, fn ($r): bool => ! in_array($r['product_id'], $followupIds, true)));
array_push($state['category_product'], ...followupAssignments(['categories' => array_values($categories), 'products' => $source['products'], 'category_product' => $memberships], $followup, []));
$plan = jsonFile($options['plan']);
[$changes, $replacement, $ids] = reviewChanges($state, $plan);
$afterCategories = $categories;
foreach ($changes as $id => $change) {
    $afterCategories[$id] = array_replace($categories[$id], $change);
}
$afterRows = array_values(array_filter($state['category_product'], fn ($r): bool => ! in_array($r['product_id'], $ids, true)));
array_push($afterRows, ...$replacement);
reviewIntegrity($afterCategories, $source['products'], $afterRows, $plan);
demand(count($ids) === 198 && count($changes) === 41 && count($plan['aliases']) === 37, 'Unexpected reviewed scope');
$primaries = [];
$counts = [];
foreach ($afterRows as $row) {
    $counts[$row['category_id']] = ($counts[$row['category_id']] ?? 0) + 1;
    if ($row['is_primary']) {
        $primaries[$row['product_id']] = $row['category_id'];
    }
}
demand(count($primaries) === 14627, 'Primary coverage regression');
demand(($counts[446] ?? 0) === 3 && ($counts[447] ?? 0) === 3, 'Pressure-care contamination');
demand(($counts[560] ?? 0) === 579, 'Clothing coverage mismatch');
demand(($counts[589] ?? 0) === 2, 'Only reviewed travel products belong in travel category');
foreach ([9715 => 249, 13635 => 1064, 13698 => 1086, 13912 => 994, 14023 => 1047, 14177 => 1151, 14239 => 1153, 11920 => 854, 11986 => 591, 11915 => 625, 11933 => 625, 11966 => 628, 12021 => 619] as $id => $target) {
    demand($primaries[$id] === $target, 'Explicit product correction failed: '.$id);
}
foreach ($plan['aliases'] as $slug => $_) {
    $id = array_column($afterCategories, 'id', 'slug')[$slug];
    demand(($counts[$id] ?? 0) === 0, 'Archived category retains memberships');
}
foreach ([9944, 10391, 10430, 10431] as $id) {
    demand($primaries[$id] === 20, 'Prior walking-aid correction regressed');
}
demand($primaries[10273] === 1012 && $primaries[10457] === -13, 'Prior accessory correction regressed');
$checks = 0;
$reject = static function (array $badState, array $badPlan) use (&$checks): void {
    $rejected = false;
    try {
        reviewChanges($badState, $badPlan);
    } catch (RuntimeException) {
        $rejected = true;
    }
    demand($rejected, 'Guard failed to reject changed input');
    $checks++;
};
$bad = $state;
foreach ($bad['products'] as &$row) {
    if ($row['id'] === 9715) {
        $row['name'] = 'Different product';
    }
}
unset($row);
$reject($bad, $plan);
$bad = $state;
foreach ($bad['category_product'] as &$row) {
    if ($row['product_id'] === 9715) {
        $row['is_primary'] = 0;
    }
}
unset($row);
$reject($bad, $plan);
$badPlan = $plan;
$badPlan['operations'][] = $badPlan['operations'][0];
$reject($state, $badPlan);
$badPlan = $plan;
$badPlan['operations'][0]['primary_slug'] = 'missing-reviewed-category';
$reject($state, $badPlan);
$badPlan = $plan;
$badPlan['category_updates'][0]['before']['name'] = 'Changed category';
$reject($state, $badPlan);
$badPlan = $plan;
foreach ($badPlan['category_updates'] as &$row) {
    if ($row['id'] === 446) {
        $row['after']['parent_slug'] = 'nowosci';
    }
}
unset($row);
$reject($state, $badPlan);
// Newly created department IDs are resolved by slug, independently of DB sequences.
$refs = [];
foreach ($originalPlan['create_categories'] as $index => $category) {
    $refs[$category['ref']] = 90000 + $index;
}
$mappedState = $state;
foreach ($mappedState['categories'] as &$row) {
    $row['id'] = $refs[$row['id']] ?? $row['id'];
    $row['parent_id'] = $row['parent_id'] === null ? null : ($refs[$row['parent_id']] ?? $row['parent_id']);
}
unset($row);
foreach ($mappedState['category_product'] as &$row) {
    $row['category_id'] = $refs[$row['category_id']] ?? $row['category_id'];
}
unset($row);
[, $mappedReplacement] = reviewChanges($mappedState, $plan);
$expected = $replacement;
foreach ($expected as &$row) {
    $row['category_id'] = $refs[$row['category_id']] ?? $row['category_id'];
}
unset($row);
$sort = fn ($a, $b): int => [$a['product_id'], $a['category_id']] <=> [$b['product_id'], $b['category_id']];
usort($expected, $sort);
usort($mappedReplacement, $sort);
demand($expected === $mappedReplacement, 'Generated ID resolution regression');
echo 'REVIEW_PRODUCTS='.count($ids)."\nCATEGORY_UPDATES=".count($changes)."\nCATEGORY_ALIASES=".count($plan['aliases'])."\nEXPECTED_ASSIGNMENTS=".count($afterRows)."\nPRESSURE_CARE_PRODUCTS=".$counts[446]."\nCLOTHING_PRODUCTS=".$counts[560]."\nALL_PRODUCT_PRIMARIES=".count($primaries)."\nNEGATIVE_GUARDS=$checks\nREVIEW_TESTS=PASS\n";
