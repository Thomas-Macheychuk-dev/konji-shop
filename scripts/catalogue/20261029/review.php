<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (! defined('CATALOGUE_REPAIR_FUNCTIONS_ONLY')) {
    define('CATALOGUE_REPAIR_FUNCTIONS_ONLY', true);
}
require_once __DIR__.'/run.php';

function reviewMembershipHash(array $rows, array $categories): string
{
    $rows = array_map(fn (array $row): array => [
        'slug' => $categories[$row['category_id']]['slug'],
        'is_primary' => (int) $row['is_primary'],
    ], $rows);
    usort($rows, fn ($a, $b): int => strcmp($a['slug'], $b['slug']));

    return digest($rows);
}

/** Resolve stable category slugs; never assume generated database IDs. */
function reviewChanges(array $state, array $plan): array
{
    demand($plan['repair_id'] === 'catalogue-20261029-review-v1', 'Unexpected review plan');
    $categories = array_column($state['categories'], null, 'id');
    $bySlug = array_column($state['categories'], 'id', 'slug');
    demand(count($bySlug) === count($categories), 'Duplicate category slugs');
    $products = array_column($state['products'], null, 'id');
    $memberships = [];
    foreach ($state['category_product'] as $row) {
        $memberships[(int) $row['product_id']][] = $row;
    }
    $categoryChanges = [];
    foreach ($plan['category_updates'] as $change) {
        $id = $bySlug[$change['slug']] ?? null;
        demand($id === $change['id'], 'Category identity drift: '.$change['slug']);
        demand(! isset($categoryChanges[$id]), 'Duplicate category update');
        $old = $categories[$id];
        demand($old['deleted_at'] === null, 'Deleted category in review');
        $parentSlug = $old['parent_id'] === null ? null : $categories[$old['parent_id']]['slug'];
        demand($change['before'] === ['name' => $old['name'], 'status' => $old['status'], 'parent_slug' => $parentSlug], 'Category changed: '.$change['slug']);
        $after = $change['after'];
        $parent = $after['parent_slug'] === null ? null : ($bySlug[$after['parent_slug']] ?? null);
        demand($after['parent_slug'] === null || $parent !== null, 'Missing target parent');
        $categoryChanges[$id] = ['parent_id' => $parent, 'name' => $after['name'], 'status' => $after['status']];
        $categories[$id] = array_replace($old, $categoryChanges[$id]);
    }
    $ids = [];
    $replacement = [];
    $oldCategories = array_column($state['categories'], null, 'id');
    foreach ($plan['operations'] as $operation) {
        $expected = $operation['product'];
        $id = (int) $expected['id'];
        demand(! isset($ids[$id]) && isset($products[$id]), 'Missing/duplicate reviewed product');
        $ids[$id] = true;
        demand(projection([$products[$id]], array_keys($expected))[0] === $expected, 'Product identity changed: '.$id);
        demand(reviewMembershipHash($memberships[$id] ?? [], $oldCategories) === $operation['before_assignment_sha256'], 'Product assignments changed: '.$id);
        $primary = $bySlug[$operation['primary_slug']] ?? null;
        demand($primary !== null, 'Missing target primary');
        $final = [];
        foreach ($operation['leaf_slugs'] as $slug) {
            demand(isset($bySlug[$slug]), 'Missing target leaf: '.$slug);
            foreach (activeChain((int) $bySlug[$slug], $categories) as $category) {
                $final[$category] = true;
            }
        }
        demand(isset($final[$primary]), 'Primary not in reviewed leaves');
        ksort($final);
        foreach ($final as $category => $_) {
            $replacement[] = ['product_id' => $id, 'category_id' => (int) $category, 'is_primary' => (int) ($category === $primary)];
        }
    }
    $finalRows = array_values(array_filter($state['category_product'], fn ($r): bool => ! isset($ids[(int) $r['product_id']])));
    array_push($finalRows, ...$replacement);
    demand(count($finalRows) === 47690, 'Unexpected final assignment count');
    reviewIntegrity($categories, $state['products'], $finalRows, $plan);

    return [$categoryChanges, $replacement, array_keys($ids)];
}

function reviewIntegrity(array $categories, array $products, array $rows, array $plan): void
{
    $categories = array_column(array_values($categories), null, 'id');
    $roots = [];
    foreach ($categories as $category) {
        if ($category['deleted_at'] === null && $category['status'] === 'active') {
            activeChain((int) $category['id'], $categories);
            if ($category['parent_id'] === null) {
                $roots[] = $category['slug'];
            }
        }
    }
    sort($roots);
    $expectedRoots = ['higiena-i-nietrzymanie-moczu', 'kompresja', 'mobilnosc-i-transport', 'obuwie', 'odziez-medyczna', 'opatrunki-i-dezynfekcja', 'opieka-nad-pacjentem', 'ortezy-i-stabilizatory', 'peruki', 'rehabilitacja', 'sprzet-medyczny', 'zdrowie-i-pielegnacja'];
    sort($expectedRoots);
    demand($roots === $expectedRoots, 'Root departments changed');
    $byProduct = [];
    $pairs = [];
    foreach ($rows as $row) {
        $pair = $row['product_id'].':'.$row['category_id'];
        demand(! isset($pairs[$pair]), 'Duplicate membership');
        $pairs[$pair] = true;
        activeChain((int) $row['category_id'], $categories);
        $byProduct[$row['product_id']][] = $row;
    }
    foreach ($products as $product) {
        $memberships = $byProduct[$product['id']] ?? [];
        $primary = array_filter($memberships, fn ($r): bool => (bool) $r['is_primary']);
        demand(count($primary) === 1, 'Primary coverage failed: '.$product['id']);
        foreach ($memberships as $row) {
            foreach (activeChain((int) $row['category_id'], $categories) as $ancestor) {
                demand(isset($pairs[$product['id'].':'.$ancestor]), 'Missing ancestor membership');
            }
        }
    }
    foreach ($plan['aliases'] as $slug => $targetSlug) {
        $bySlug = array_column($categories, null, 'slug');
        demand($bySlug[$slug]['status'] === 'archived', 'Alias source must be archived');
        demand($bySlug[$targetSlug]['status'] === 'active' && $bySlug[$targetSlug]['deleted_at'] === null, 'Alias target must be active');
        demand(! isset($plan['aliases'][$targetSlug]), 'Alias chain');
    }
}

if (defined('CATALOGUE_REVIEW_FUNCTIONS_ONLY') && CATALOGUE_REVIEW_FUNCTIONS_ONLY) {
    return;
}

$committed = false;
try {
    $options = getopt('', ['mode:', 'plan:', 'app:']);
    $mode = $options['mode'] ?? 'dry-run';
    demand(in_array($mode, ['dry-run', 'apply', 'verify', 'rollback'], true), 'Unsupported review mode');
    $appPath = $options['app'] ?? getcwd();
    require $appPath.'/vendor/autoload.php';
    $app = require $appPath.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    demand(DB::connection()->getDriverName() === 'mysql', 'MySQL required');
    echo "MODE=$mode\nAPP_ENV=".$app->environment()."\n";
    $planPath = $options['plan'] ?? '/tmp/konji-catalogue-review.json';
    demand(hash_file('sha256', $planPath) === '783af9a139d911e4bccee4960e0040c06a952b916d1a86e8a4368d04a85647ba', 'Review plan checksum mismatch');
    $plan = jsonFile($planPath);
    $directory = storage_path('app/private/catalogue-repairs');
    $backupPath = $directory.'/catalogue-20261029-review-v1.json';
    $receiptPath = $backupPath.'.receipt.json';
    $fingerprint = hash('sha256', (string) config('database.connections.'.config('database.default').'.host').'|'.DB::connection()->getDatabaseName());
    if ($mode === 'verify' || $mode === 'rollback') {
        $receipt = jsonFile($receiptPath);
        $backup = jsonFile($backupPath);
        demand($fingerprint === $receipt['database_fingerprint'], 'Wrong database');
        demand(hash_file('sha256', $backupPath) === $receipt['backup_sha256'], 'Backup checksum mismatch');
        DB::transaction(function () use ($mode, $receipt, $backup, $plan): void {
            $state = currentSnapshot($mode === 'rollback');
            receiptMatches($state, $receipt);
            reviewIntegrity($state['categories'], $state['products'], $state['category_product'], $plan);
            if ($mode === 'rollback') {
                foreach ($backup['categories'] as $row) {
                    DB::table('categories')->where('id', $row['id'])->update($row);
                }
                DB::table('category_product')->whereIn('product_id', $backup['product_ids'])->delete();
                foreach (array_chunk($backup['assignments'], 400) as $rows) {
                    DB::table('category_product')->insert($rows);
                }
                $restored = currentSnapshot(false);
                demand(digest($restored['categories']) === $backup['before_category_hash'], 'Category rollback mismatch');
                demand(digest($restored['category_product']) === $backup['before_assignment_hash'], 'Assignment rollback mismatch');
            }
        });
        cacheBump();
        echo 'REVIEW_'.strtoupper($mode)."=PASS\n";
        exit(0);
    }
    if ($mode === 'apply') {
        demand(hash_file('sha256', $appPath.'/app/Http/Controllers/CategoryProductIndexController.php') === 'ba118ba079f175ca9aa5341338ab020f43ae8b9fff7d99770958c651c6c99e2f', 'Deploy reviewed breadcrumb/alias code before data apply');
        demand(hash_file('sha256', $appPath.'/resources/views/pages/categories/show.blade.php') === '99aefeb6b1dac8defd43adb00b0d48dbcd0037fb6413b916453776c28960aed7', 'Deploy reviewed breadcrumb/alias code before data apply');
    }
    $previousBackup = $directory.'/catalogue-20261029-followup-v1.json';
    $previousReceipt = jsonFile($previousBackup.'.receipt.json');
    demand(hash_file('sha256', $previousBackup) === $previousReceipt['backup_sha256'], 'Previous backup checksum mismatch');
    demand($fingerprint === $previousReceipt['database_fingerprint'], 'Wrong database');
    DB::transaction(function () use ($mode, $plan, $previousReceipt, $backupPath, $receiptPath, $fingerprint): void {
        $state = currentSnapshot($mode === 'apply');
        receiptMatches($state, $previousReceipt);
        [$categoryChanges, $replacement, $ids] = reviewChanges($state, $plan);
        demand(count($ids) === 198 && count($categoryChanges) === 41, 'Unexpected repair scope');
        echo "PREVIOUS_REPAIR_STATE=PASS\nREVIEW_PRODUCTS=".count($ids)."\nCATEGORY_UPDATES=".count($categoryChanges)."\nCATEGORY_ALIASES=".count($plan['aliases'])."\nCATEGORY_GRAPH=PASS\nPRIMARY_COVERAGE=PASS\n";
        if ($mode === 'dry-run') {
            return;
        }
        umask(0077);
        demand(! file_exists($backupPath) && ! file_exists($receiptPath), 'Backup/receipt already exists; use verify');
        $oldRows = array_values(array_filter($state['category_product'], fn ($r): bool => in_array((int) $r['product_id'], $ids, true)));
        $oldCategories = array_values(array_filter($state['categories'], fn ($r): bool => isset($categoryChanges[$r['id']])));
        writeExclusive($backupPath, ['product_ids' => $ids, 'categories' => $oldCategories, 'assignments' => $oldRows, 'before_category_hash' => digest($state['categories']), 'before_assignment_hash' => digest($state['category_product'])]);
        $timestamp = now()->toDateTimeString();
        foreach ($categoryChanges as $id => $changes) {
            DB::table('categories')->where('id', $id)->update($changes + ['updated_at' => $timestamp]);
        }
        $pairs = [];
        foreach ($oldRows as $row) {
            $pairs[$row['product_id'].':'.$row['category_id']] = $row;
        }
        $insert = [];
        foreach ($replacement as $row) {
            $old = $pairs[$row['product_id'].':'.$row['category_id']] ?? null;
            $insert[] = ['id' => $old['id'] ?? null] + $row + ['created_at' => $old['created_at'] ?? $timestamp, 'updated_at' => $timestamp];
        }
        DB::table('category_product')->whereIn('product_id', $ids)->delete();
        foreach (array_chunk($insert, 400) as $rows) {
            DB::table('category_product')->insert($rows);
        }
        $after = currentSnapshot(false);
        reviewIntegrity($after['categories'], $after['products'], $after['category_product'], $plan);
        demand(digest($after['products']) === digest($state['products']), 'Product rows changed');
        $untouched = fn ($r): bool => ! in_array((int) $r['product_id'], $ids, true);
        demand(digest(array_values(array_filter($state['category_product'], $untouched))) === digest(array_values(array_filter($after['category_product'], $untouched))), 'Unrelated assignments changed');
        $unmodifiedCategories = fn ($r): bool => ! isset($categoryChanges[$r['id']]);
        demand(digest(array_values(array_filter($state['categories'], $unmodifiedCategories))) === digest(array_values(array_filter($after['categories'], $unmodifiedCategories))), 'Unrelated categories changed');
        $actual = projection(array_values(array_filter($after['category_product'], fn ($r): bool => ! $untouched($r))), ['product_id', 'category_id', 'is_primary']);
        $sort = fn ($a, $b): int => [$a['product_id'], $a['category_id']] <=> [$b['product_id'], $b['category_id']];
        usort($actual, $sort);
        usort($replacement, $sort);
        demand($actual === $replacement, 'Stored assignments differ from plan');
        writeExclusive($receiptPath, ['repair_id' => $plan['repair_id'], 'database_fingerprint' => $fingerprint, 'backup_sha256' => hash_file('sha256', $backupPath), 'after_hashes' => ['categories' => digest($after['categories']), 'category_product' => digest($after['category_product'])], 'product_identity_hash' => digest(projection($after['products'], ['id', 'name', 'slug', 'external_source', 'external_id', 'status', 'deleted_at']))]);
    });
    if ($mode === 'apply') {
        $committed = true;
        echo "REVIEW_DATABASE_COMMIT=PASS\nBACKUP=$backupPath\nRECEIPT=$receiptPath\n";
        cacheBump();
    }
    echo 'REVIEW_'.strtoupper(str_replace('-', '_', $mode))."=PASS\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'REVIEW=FAIL '.$exception->getMessage()."\n");
    if ($committed) {
        fwrite(STDERR, "DATABASE_ALREADY_COMMITTED=1; retry --mode=verify.\n");
    }
    exit(1);
}
