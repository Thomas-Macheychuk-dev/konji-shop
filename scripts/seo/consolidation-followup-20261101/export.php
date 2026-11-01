<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/ReferenceAudit.php';
require __DIR__.'/AppliedReceipt.php';
require __DIR__.'/PreservationExport.php';
$review = json_decode(file_get_contents(__DIR__.'/review.json'), true, flags: JSON_THROW_ON_ERROR);
ProductSeoAppliedReceipt::verify($review['prior_backup'], $review['prior_plan_sha256']);
// The receipt is read from private files; database work consists of SELECTs only.
$data = DB::transaction(static fn (): array => (new ProductSeoPreservationExport($review))->build());
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
