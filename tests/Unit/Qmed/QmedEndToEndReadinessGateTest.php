<?php

declare(strict_types=1);

namespace Tests\Unit\Qmed;

use App\Services\Qmed\QmedCommercialReadinessService;
use App\Services\Qmed\QmedConfigurationReconciliationService;
use App\Services\Qmed\QmedEndToEndReadinessGate;
use App\Services\Qmed\QmedSupplierManifestService;
use App\Services\Qmed\QmedSupplierResponseValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QmedEndToEndReadinessGateTest extends TestCase
{
    public function test_fails_closed_for_missing_input(): void
    {
        $this->expectException(RuntimeException::class);
        $this->gate()->verify([]);
    }

    public function test_detects_stale_source_hash_before_replay(): void
    {
        $this->expectExceptionMessage('Pipeline hash mismatch: evidence.source_report_sha256');
        $raw = ['source' => '{}', 'csv' => '',
            'evidence' => json_encode(['source_report_sha256' => str_repeat('a', 64)]),
            'audit' => '{}', 'manifest' => '{}', 'validation' => '{}', 'reconciliation' => '{}'];
        $this->gate()->verify($raw);
    }

    public function test_detects_broken_validation_csv_link(): void
    {
        $this->expectExceptionMessage('Pipeline hash mismatch: validation.supplier_csv_sha256');
        $raw = ['source' => '{}', 'csv' => 'changed', 'evidence' => '{}',
            'audit' => '{}', 'manifest' => '{}', 'validation' => '{}', 'reconciliation' => '{}'];
        $documents = ['evidence' => ['source_report_sha256' => hash('sha256', $raw['source'])],
            'audit' => ['source_report_sha256' => hash('sha256', $raw['source']),
                'evidence_report_sha256' => ''],
            'manifest' => [], 'validation' => [], 'reconciliation' => []];
        $raw['evidence'] = json_encode($documents['evidence']);
        $documents['audit']['evidence_report_sha256'] = hash('sha256', $raw['evidence']);
        $raw['audit'] = json_encode($documents['audit']);
        $documents['manifest'] = ['source_report_sha256' => hash('sha256', $raw['source']),
            'audit_report_sha256' => hash('sha256', $raw['audit'])];
        $raw['manifest'] = json_encode($documents['manifest']);
        $documents['validation'] = ['manifest_report_sha256' => hash('sha256', $raw['manifest']),
            'supplier_csv_sha256' => hash('sha256', 'original')];
        $raw['validation'] = json_encode($documents['validation']);
        $documents['reconciliation'] = ['evidence_report_sha256' => hash('sha256', $raw['evidence']),
            'validation_report_sha256' => hash('sha256', $raw['validation']),
            'supplier_csv_sha256' => hash('sha256', $raw['csv'])];
        $raw['reconciliation'] = json_encode($documents['reconciliation']);
        $this->gate()->verify($raw);
    }

    private function gate(): QmedEndToEndReadinessGate
    {
        return new QmedEndToEndReadinessGate(
            new QmedCommercialReadinessService(),
            new QmedSupplierManifestService(),
            new QmedSupplierResponseValidator(),
            new QmedConfigurationReconciliationService(),
        );
    }
}
