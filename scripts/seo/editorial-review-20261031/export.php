<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/Metadata.php';
require __DIR__.'/Exporter.php';
$reviewed = require __DIR__.'/reviewed-titles.php';
$directory = realpath($argv[1] ?? '');
$private = realpath(storage_path('app/private'));
if ($directory === false || $private === false || ! str_starts_with($directory.'/', $private.'/')) {
    throw new RuntimeException('Pass the existing private database repair directory.');
}
$path = $directory.'/plan-and-backup.json';
if (! is_file($path) || ! is_file($directory.'/plan.sha256')
    || ! hash_equals(trim(file_get_contents($directory.'/plan.sha256')), hash_file('sha256', $path))) {
    throw new RuntimeException('Database repair backup checksum mismatch.');
}
$receipt = json_decode(file_get_contents($directory.'/receipt.json'), true, flags: JSON_THROW_ON_ERROR);
if (($receipt['state'] ?? null) !== 'applied' || ($receipt['plan_sha256'] ?? null) !== hash_file('sha256', $path)) {
    throw new RuntimeException('Expected the applied database repair receipt.');
}
$plan = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$export = (new ProductSeoEditorialExport($reviewed))->build($plan);
$export['source_repair_sha256'] = hash_file('sha256', $path);
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
