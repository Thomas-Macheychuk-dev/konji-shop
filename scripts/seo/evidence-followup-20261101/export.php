<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/Metadata.php';
require __DIR__.'/DuplicateExport.php';
// One transaction gives a consistent catalogue snapshot. The exporter issues
// SELECTs only and includes no customer/order details.
$data = DB::transaction(fn (): array => (new ProductSeoDuplicateExport)->build());
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
