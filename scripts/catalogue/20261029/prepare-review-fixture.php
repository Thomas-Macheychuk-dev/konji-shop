<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

define('CATALOGUE_REPAIR_FUNCTIONS_ONLY', true);
require __DIR__.'/run.php';

try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $directory = storage_path('app/private/catalogue-repairs');
    $originalPath = $directory.'/catalogue-20261029-v1.json';
    $originalReceipt = jsonFile($originalPath.'.receipt.json');
    $original = jsonFile($originalPath);
    demand(hash_file('sha256', $originalPath) === $originalReceipt['backup_sha256'], 'Original backup checksum mismatch');
    $previousPath = $directory.'/catalogue-20261029-followup-v1.json';
    $previousReceipt = jsonFile($previousPath.'.receipt.json');
    demand(hash_file('sha256', $previousPath) === $previousReceipt['backup_sha256'], 'Follow-up backup checksum mismatch');
    $fingerprint = hash('sha256', (string) config('database.connections.'.config('database.default').'.host').'|'.Illuminate\Support\Facades\DB::connection()->getDatabaseName());
    demand($fingerprint === $originalReceipt['database_fingerprint'] && $fingerprint === $previousReceipt['database_fingerprint'], 'Wrong database');
    $state = currentSnapshot(false);
    receiptMatches($state, $previousReceipt);
    $products = projection($state['products'], ['id', 'name', 'slug', 'external_source', 'external_id', 'status', 'deleted_at']);
    demand(digest($products) === $originalReceipt['product_identity_hash'], 'Original product identities changed');
    // Recreate the original export's relevant fields from persistent, verified
    // backups. Container recreation may remove the original /tmp export.
    $source = [
        'categories' => projection($original['categories'], ['id', 'parent_id', 'name', 'slug', 'status', 'deleted_at']),
        'products' => $products,
        'assignments' => projection($original['category_product'], ['product_id', 'category_id', 'is_primary']),
    ];
    $path = '/tmp/konji-review-frozen-source.json';
    umask(0077);
    if (is_file($path)) {
        demand(digest(jsonFile($path)) === digest($source), 'Existing simulation input differs');
    } else {
        writeExclusive($path, $source);
    }
    echo "SIMULATION_INPUT_FROM_VERIFIED_BACKUPS=PASS\nSIMULATION_SOURCE=$path\nDATABASE_WRITES=0\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'SIMULATION_INPUT=FAIL '.$exception->getMessage()."\n");
    exit(1);
}
