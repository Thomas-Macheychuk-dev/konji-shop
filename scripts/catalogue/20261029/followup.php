<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (! defined('CATALOGUE_REPAIR_FUNCTIONS_ONLY')) {
    define('CATALOGUE_REPAIR_FUNCTIONS_ONLY', true);
}
require_once __DIR__.'/run.php';

/** Return replacement memberships for only the explicitly reviewed products. */
function followupAssignments(array $state, array $operations, array $refs): array
{
    $categories = array_column($state['categories'], null, 'id');
    $products = array_column($state['products'], null, 'id');
    $byProduct = [];
    foreach ($state['category_product'] as $row) {
        $byProduct[(int) $row['product_id']][] = $row;
    }
    $resolve = static fn (int $id): int => $refs[$id] ?? $id;
    $result = [];
    $seen = [];
    foreach ($operations as $operation) {
        $id = (int) $operation['product_id'];
        demand(! isset($seen[$id]), 'Duplicate follow-up product');
        $seen[$id] = true;
        demand(isset($products[$id]), 'Missing follow-up product');
        foreach (['name', 'external_source', 'external_id'] as $field) {
            demand($products[$id][$field] === $operation[$field], 'Follow-up product identity mismatch: '.$id);
        }
        $old = $byProduct[$id] ?? [];
        $primaries = array_values(array_filter($old, fn ($row): bool => (bool) $row['is_primary']));
        $oldPrimary = $resolve((int) $operation['old_ref']);
        $newPrimary = $resolve((int) $operation['new_ref']);
        demand(count($primaries) === 1 && (int) $primaries[0]['category_id'] === $oldPrimary, 'Follow-up primary changed: '.$id);
        $ancestors = [];
        foreach ($old as $row) {
            foreach (array_slice(activeChain((int) $row['category_id'], $categories), 1) as $ancestor) {
                $ancestors[$ancestor] = true;
            }
        }
        $leaves = [];
        foreach ($old as $row) {
            if (! isset($ancestors[$row['category_id']])) {
                $leaves[(int) $row['category_id']] = true;
            }
        }
        unset($leaves[$oldPrimary]);
        $leaves[$newPrimary] = true;
        $final = [];
        foreach ($leaves as $leaf => $_) {
            foreach (activeChain((int) $leaf, $categories) as $category) {
                $final[$category] = true;
            }
        }
        demand(isset($final[$newPrimary]) && ! isset($final[$oldPrimary]), 'Old category retained unexpectedly: '.$id);
        ksort($final);
        foreach ($final as $category => $_) {
            $result[] = ['product_id' => $id, 'category_id' => (int) $category, 'is_primary' => (int) ($category === $newPrimary)];
        }
    }
    return $result;
}

if (defined('CATALOGUE_FOLLOWUP_FUNCTIONS_ONLY') && CATALOGUE_FOLLOWUP_FUNCTIONS_ONLY) {
    return;
}

$committed = false;
try {
    $options = getopt('', ['mode:', 'operations:', 'plan:', 'app:']);
    $mode = $options['mode'] ?? 'dry-run';
    demand(in_array($mode, ['dry-run', 'apply', 'verify', 'rollback'], true), 'Unsupported follow-up mode');
    $appPath = $options['app'] ?? getcwd();
    require $appPath.'/vendor/autoload.php';
    $app = require $appPath.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    demand(DB::connection()->getDriverName() === 'mysql', 'MySQL required');
    $originalBackup = storage_path('app/private/catalogue-repairs/catalogue-20261029-v1.json');
    $backupPath = storage_path('app/private/catalogue-repairs/catalogue-20261029-followup-v1.json');
    $receiptPath = $backupPath.'.receipt.json';
    $fingerprint = hash('sha256', (string) config('database.connections.'.config('database.default').'.host').'|'.DB::connection()->getDatabaseName());
    if ($mode === 'verify' || $mode === 'rollback') {
        $receipt = jsonFile($receiptPath);
        $backup = jsonFile($backupPath);
        demand(hash_file('sha256', $backupPath) === $receipt['backup_sha256'], 'Follow-up backup checksum mismatch');
        demand($fingerprint === $receipt['database_fingerprint'], 'Wrong database');
        DB::transaction(function () use ($mode, $receipt, $backup): void {
            $state = currentSnapshot($mode === 'rollback');
            receiptMatches($state, $receipt);
            if ($mode === 'rollback') {
                DB::table('category_product')->whereIn('product_id', $backup['product_ids'])->delete();
                foreach (array_chunk($backup['assignments'], 400) as $rows) {
                    DB::table('category_product')->insert($rows);
                }
                $restored = currentSnapshot(false);
                demand(digest($restored['category_product']) === $backup['before_assignment_hash'], 'Follow-up rollback failed');
                demand(digest($restored['categories']) === $backup['before_category_hash'], 'Unexpected category changes');
            }
        });
        cacheBump();
        echo 'FOLLOWUP_'.strtoupper($mode)."=PASS\n";
        exit(0);
    }
    $operationsFile = $options['operations'] ?? '/tmp/konji-catalogue-followup.json';
    $operationPlan = jsonFile($operationsFile);
    demand($operationPlan['repair_id'] === 'catalogue-20261029-followup-v1', 'Unexpected follow-up plan');
    $operations = $operationPlan['operations'];
    demand(count($operations) === 18, 'Expected the 18 explicitly reviewed corrections');
    $originalReceipt = jsonFile($originalBackup.'.receipt.json');
    demand(hash_file('sha256', $originalBackup) === $originalReceipt['backup_sha256'], 'Original backup checksum mismatch');
    demand($fingerprint === $originalReceipt['database_fingerprint'], 'Wrong database');
    $originalPlanPath = $options['plan'] ?? '/tmp/konji-catalogue-plan-20261029.json';
    demand(hash_file('sha256', $originalPlanPath) === $originalReceipt['plan_sha256'], 'Original plan checksum mismatch');
    $originalPlan = jsonFile($originalPlanPath);
    demand(count($originalPlan['create_categories']) === count($originalReceipt['created_category_ids']), 'Category reference count mismatch');
    $refs = array_combine(array_column($originalPlan['create_categories'], 'ref'), $originalReceipt['created_category_ids']);
    DB::transaction(function () use ($mode, $operations, $refs, $originalReceipt, $backupPath, $receiptPath, $fingerprint): void {
        $state = currentSnapshot($mode === 'apply');
        receiptMatches($state, $originalReceipt);
        $replacement = followupAssignments($state, $operations, $refs);
        $ids = array_column($operations, 'product_id');
        echo "ORIGINAL_REPAIR_RECEIPT=PASS\nEXPLICIT_PRODUCT_CORRECTIONS=".count($ids)."\n";
        if ($mode === 'dry-run') {
            return;
        }
        umask(0077);
        demand(! file_exists($backupPath) && ! file_exists($receiptPath), 'Follow-up backup already exists; use verify');
        $oldRows = array_values(array_filter($state['category_product'], fn ($row): bool => in_array((int) $row['product_id'], $ids, true)));
        writeExclusive($backupPath, ['product_ids' => $ids, 'assignments' => $oldRows, 'before_category_hash' => digest($state['categories']), 'before_assignment_hash' => digest($state['category_product'])]);
        $pairs = [];
        foreach ($oldRows as $row) {
            $pairs[$row['product_id'].':'.$row['category_id']] = $row;
        }
        $timestamp = now()->toDateTimeString();
        $insert = [];
        foreach ($replacement as $row) {
            $old = $pairs[$row['product_id'].':'.$row['category_id']] ?? null;
            $insert[] = ['id' => $old['id'] ?? null, 'product_id' => $row['product_id'], 'category_id' => $row['category_id'], 'is_primary' => $row['is_primary'], 'created_at' => $old['created_at'] ?? $timestamp, 'updated_at' => $timestamp];
        }
        DB::table('category_product')->whereIn('product_id', $ids)->delete();
        foreach (array_chunk($insert, 400) as $rows) {
            DB::table('category_product')->insert($rows);
        }
        $after = currentSnapshot(false);
        demand(digest($after['categories']) === digest($state['categories']), 'Follow-up changed category rows');
        demand(digest($after['products']) === digest($state['products']), 'Follow-up changed product rows');
        $sort = fn ($a, $b): int => [$a['product_id'], $a['category_id']] <=> [$b['product_id'], $b['category_id']];
        $actual = array_values(array_filter($after['category_product'], fn ($row): bool => in_array((int) $row['product_id'], $ids, true)));
        $actual = projection($actual, ['product_id', 'category_id', 'is_primary']);
        usort($actual, $sort);
        usort($replacement, $sort);
        demand($actual === $replacement, 'Follow-up assignments do not match explicit corrections');
        $untouchedBefore = array_values(array_filter($state['category_product'], fn ($row): bool => ! in_array((int) $row['product_id'], $ids, true)));
        $untouchedAfter = array_values(array_filter($after['category_product'], fn ($row): bool => ! in_array((int) $row['product_id'], $ids, true)));
        demand(digest($untouchedBefore) === digest($untouchedAfter), 'Unrelated product assignments changed');
        writeExclusive($receiptPath, ['database_fingerprint' => $fingerprint, 'backup_sha256' => hash_file('sha256', $backupPath), 'after_hashes' => ['categories' => digest($after['categories']), 'category_product' => digest($after['category_product'])], 'product_identity_hash' => digest(projection($after['products'], ['id', 'name', 'slug', 'external_source', 'external_id', 'status', 'deleted_at']))]);
    });
    if ($mode === 'apply') {
        $committed = true;
        echo "FOLLOWUP_DATABASE_COMMIT=PASS\n";
        cacheBump();
    }
    echo 'FOLLOWUP_'.strtoupper(str_replace('-', '_', $mode))."=PASS\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FOLLOWUP=FAIL '.$exception->getMessage()."\n");
    if ($committed) {
        fwrite(STDERR, "DATABASE_ALREADY_COMMITTED=1; retry --mode=verify.\n");
    }
    exit(1);
}
