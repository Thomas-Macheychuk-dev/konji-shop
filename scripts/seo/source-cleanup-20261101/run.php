<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require dirname(__DIR__).'/product-database-20261031/Metadata.php';
require dirname(__DIR__).'/editorial-repair-20261031/EditorialRepair.php';
require dirname(__DIR__).'/colour-correction-20261101/AppliedReceipt.php';
require __DIR__.'/SourceRepair.php';
$manifest = json_decode(file_get_contents(__DIR__.'/review.json'), true, flags: JSON_THROW_ON_ERROR);

if (($manifest['repair'] ?? null) !== 'product-source-cleanup-20261101-v1') {
    throw new RuntimeException('Unexpected source cleanup batch.');
}
foreach ($manifest['prior_receipts'] as $prior) {
    ProductSeoAppliedReceipt::verify($prior['backup'], $prior['sha256']);
}
echo 'PRIOR_APPLIED_RECEIPT=PASS'.PHP_EOL;
echo 'BATCH='.$manifest['repair'].PHP_EOL;

$mode = $argv[1] ?? 'dry-run';
$directory = $argv[2] ?? '';
if (! in_array($mode, ['dry-run', 'apply', 'verify', 'rollback'], true)
    || ! is_dir($directory)
    || ! str_starts_with(realpath($directory).'/', realpath(storage_path('app/private')).'/')) {
    fwrite(STDERR, 'Usage: php run.php dry-run|apply|verify|rollback PRIVATE_EXISTING_DIRECTORY'.PHP_EOL);
    exit(1);
}
umask(0077);
$lock = fopen($directory.'/repair.lock', 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another repair is using this directory.');
}
$path = $directory.'/plan-and-backup.json';
$hashPath = $directory.'/plan.sha256';
$receiptPath = $directory.'/receipt.json';
$repair = new ProductSourceCleanup($manifest);
$write = static function (string $target, array $data): void {
    $temporary = $target.'.tmp';
    $bytes = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! rename($temporary, $target)) {
        throw new RuntimeException('Could not persist repair artifact '.$target);
    }
};
try {
    echo 'MODE='.$mode.PHP_EOL;
    if ($mode === 'dry-run') {
        if (file_exists($path) || file_exists($receiptPath)) {
            throw new RuntimeException('Use a fresh private directory; existing plan/backup will not be overwritten.');
        }
        $plan = $repair->plan();
        if ($plan['stats']['active_products'] === 0) {
            throw new RuntimeException('No active products found; refusing an empty catalogue repair.');
        }
        $write($path, $plan);
        if (file_put_contents($hashPath, hash_file('sha256', $path).PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Could not persist plan checksum.');
        }
        echo json_encode(['stats' => $plan['stats'], 'reasons' => $plan['reasons'], 'sample_changes' => array_slice($plan['operations'], 0, 8)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        echo 'PLAN_AND_BACKUP='.$path.PHP_EOL.'PLAN_SHA256='.hash_file('sha256', $path).PHP_EOL;
        echo 'DATABASE_WRITES=0'.PHP_EOL.'PRODUCT_SOURCE_CLEANUP_DRY_RUN=PASS'.PHP_EOL;
    } else {
        if (! file_exists($path) || ! file_exists($hashPath) || ! hash_equals(trim(file_get_contents($hashPath)), hash_file('sha256', $path))) {
            throw new RuntimeException('Plan/backup checksum mismatch.');
        }
        $plan = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $receipt = file_exists($receiptPath) ? json_decode(file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR) : null;
        if ($receipt !== null && ($receipt['plan_sha256'] ?? '') !== hash_file('sha256', $path)) {
            throw new RuntimeException('Receipt does not match this plan.');
        }
        if ($mode === 'apply') {
            if (($receipt['state'] ?? null) === 'applied') {
                ProductSourceCleanup::invalidateCache();
                $result = $repair->verify($plan);
                echo 'ALREADY_APPLIED=PASS'.PHP_EOL;
            } else {
                if ($receipt !== null) {
                    throw new RuntimeException('This directory already has a repair receipt; do not reapply.');
                }
                // Backup and intended values already exist on disk before the transaction.
                // Recover a completed transaction if writing its receipt was interrupted.
                $alreadyMatches = false;
                try {
                    $result = $repair->verify($plan);
                    $alreadyMatches = true;
                } catch (RuntimeException) {
                    // Expected before the first apply; the source-checked transaction follows.
                }
                if (! $alreadyMatches) {
                    $repair->apply($plan);
                }
                $write($receiptPath, ['state' => 'applied', 'plan_sha256' => hash_file('sha256', $path), 'committed_at' => now()->toIso8601String(), 'changed_products' => count($plan['operations'])]);
                echo ($alreadyMatches ? 'DATABASE_ALREADY_MATCHES=PASS' : 'DATABASE_COMMIT=PASS').PHP_EOL;
                ProductSourceCleanup::invalidateCache();
                $result = $repair->verify($plan);
            }
            echo 'CACHE_INVALIDATION=PASS'.PHP_EOL;
            echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL.'PRODUCT_SOURCE_CLEANUP_APPLY=PASS'.PHP_EOL;
        } elseif ($mode === 'verify') {
            if (($receipt['state'] ?? null) !== 'applied') {
                throw new RuntimeException('No applied repair receipt in this directory.');
            }
            $result = $repair->verify($plan);
            $matched = $repair->verifyRenderedMetadata();
            // Also repairs cache invalidation if a previous post-commit cache call failed.
            ProductSourceCleanup::invalidateCache();
            echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL.'RENDERED_METADATA_MATCH='.$matched.PHP_EOL.'PRODUCT_SOURCE_CLEANUP_VERIFY=PASS'.PHP_EOL;
        } else {
            if (($receipt['state'] ?? null) !== 'applied') {
                throw new RuntimeException('Rollback requires an applied repair receipt.');
            }
            $repair->rollback($plan);
            $receipt['rolled_back_at'] = now()->toIso8601String();
            $receipt['state'] = 'rolled_back';
            $write($receiptPath, $receipt);
            ProductSourceCleanup::invalidateCache();
            echo 'PRODUCT_SOURCE_CLEANUP_ROLLBACK=PASS'.PHP_EOL;
        }
        echo 'BACKUP='.$path.PHP_EOL.'RECEIPT='.$receiptPath.PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'PRODUCT_SOURCE_CLEANUP_REPAIR=FAIL '.$exception->getMessage().PHP_EOL);
    exit(1);
}
