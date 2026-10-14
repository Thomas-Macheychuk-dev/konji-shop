<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use RuntimeException;

/** Replays the evidence-only pipeline. Never authorises commercial import. */
final class QmedEndToEndReadinessGate
{
    public function __construct(
        private readonly QmedCommercialReadinessService $auditor,
        private readonly QmedSupplierManifestService $exporter,
        private readonly QmedSupplierResponseValidator $validator,
        private readonly QmedConfigurationReconciliationService $reconciler,
    ) {}

    /** @param array<string,string> $raw keyed by source,evidence,audit,manifest,csv,validation,reconciliation */
    public function verify(array $raw): array
    {
        $expectedKeys = ['source', 'evidence', 'audit', 'manifest', 'csv', 'validation', 'reconciliation'];
        foreach ($expectedKeys as $key) {
            if (!isset($raw[$key]) || !is_string($raw[$key])) {
                throw new RuntimeException('Missing pipeline input: '.$key);
            }
        }
        $decoded = [];
        foreach (['source', 'evidence', 'audit', 'manifest', 'validation', 'reconciliation'] as $key) {
            $decoded[$key] = json_decode($raw[$key], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded[$key])) throw new RuntimeException('Invalid JSON: '.$key);
        }
        $hashChecks = [
            ['evidence', 'source_report_sha256', 'source'],
            ['audit', 'source_report_sha256', 'source'],
            ['audit', 'evidence_report_sha256', 'evidence'],
            ['manifest', 'source_report_sha256', 'source'],
            ['manifest', 'audit_report_sha256', 'audit'],
            ['validation', 'manifest_report_sha256', 'manifest'],
            ['validation', 'supplier_csv_sha256', 'csv'],
            ['reconciliation', 'evidence_report_sha256', 'evidence'],
            ['reconciliation', 'validation_report_sha256', 'validation'],
            ['reconciliation', 'supplier_csv_sha256', 'csv'],
        ];
        foreach ($hashChecks as [$report, $field, $input]) {
            if (($decoded[$report][$field] ?? null) !== hash('sha256', $raw[$input])) {
                throw new RuntimeException('Pipeline hash mismatch: '.$report.'.'.$field);
            }
        }
        if (($decoded['evidence']['analysis_type'] ?? null) !== 'qmed08g_consolidated_evidence_review'
            || ($decoded['audit']['analysis_type'] ?? null) !== 'qmed09b_commercial_readiness_evidence_only'
            || ($decoded['manifest']['analysis_type'] ?? null) !== 'qmed09c_supplier_data_requirements'
            || ($decoded['validation']['analysis_type'] ?? null) !== 'qmed09d_supplier_response_validation'
            || ($decoded['reconciliation']['analysis_type'] ?? null) !== 'qmed09f_configuration_evidence_reconciliation') {
            throw new RuntimeException('Unexpected pipeline report type.');
        }
        $audit = $this->auditor->audit($decoded['source'], $decoded['evidence']);
        $this->same('audit', $audit['summary'], $decoded['audit']['summary'] ?? null);
        $this->same('audit products', $audit['products'], $decoded['audit']['products'] ?? null);

        $export = $this->exporter->build($decoded['source'], $decoded['audit']);
        $this->same('manifest summary', $export['manifest']['summary'], $decoded['manifest']['summary'] ?? null);
        $this->same('manifest products', $export['manifest']['products'], $decoded['manifest']['products'] ?? null);
        if ($this->exporter->csv($export['rows']) !== $raw['csv']) {
            // An actual supplier response may differ from the blank template. Verify
            // template structure only when this is a completed supplier response.
            // Never mistake a changed supplier CSV for a canonical blank template.
            $this->validateCsvHeader($raw['csv']);
        }
        $validation = $this->validator->validate($decoded['manifest'], $raw['csv']);
        $this->same('validation summary', $validation['summary'], $decoded['validation']['summary'] ?? null);
        $this->same('validation records', $validation['records'], $decoded['validation']['records'] ?? null);
        $reconciliation = $this->reconciler->reconcile($decoded['evidence'], $decoded['validation'], $raw['csv']);
        $this->same('reconciliation summary', $reconciliation['summary'], $decoded['reconciliation']['summary'] ?? null);
        $this->same('reconciliation records', $reconciliation['records'], $decoded['reconciliation']['records'] ?? null);

        $summary = $reconciliation['summary'];
        $validated = $validation['summary'];
        if (($decoded['evidence']['summary']['products'] ?? null) !== 156
            || ($decoded['evidence']['summary']['candidate_size_entries'] ?? null) !== 578
            || ($audit['summary']['products'] ?? null) !== 156
            || ($export['manifest']['summary']['products'] ?? null) !== 156
            || $summary['rows'] !== $validated['rows']
            || array_sum(array_map(static fn (string $key): int => (int) $summary[$key], [
                'supported_by_manufacturer_evidence', 'requires_review', 'conflicting_evidence', 'not_evaluated',
            ])) !== $summary['rows']) {
            throw new RuntimeException('Qmed baseline or reconciliation totals changed.');
        }
        foreach ([$decoded['evidence']['summary'], $audit['summary'], $export['manifest']['summary'], $validated, $summary] as $section) {
            if (($section['commercially_approved'] ?? null) !== 0
                || ($section['database_writes'] ?? null) !== 0
                || ($section['variants_created'] ?? null) !== 0) {
                throw new RuntimeException('Commercial safety invariant failed.');
            }
        }
        if (($audit['summary']['commercially_ready'] ?? null) !== 0) {
            throw new RuntimeException('Commercial readiness cannot be inferred.');
        }
        return [
            'source' => 'qmed',
            'analysis_type' => 'qmed09g_end_to_end_readiness_gate',
            'input_sha256' => array_map(static fn (string $text): string => hash('sha256', $text), $raw),
            'summary' => [
                'products' => 156,
                'candidate_size_entries' => 578,
                'supplier_rows' => $validated['rows'],
                'validation_evidence_complete' => $validated['evidence_complete'],
                'validation_review_required' => $validated['review_required'],
                'validation_rejected' => $validated['rejected'],
                'configurations_supported' => $summary['supported_by_manufacturer_evidence'],
                'configurations_require_review' => $summary['requires_review'],
                'configurations_conflicting' => $summary['conflicting_evidence'],
                'configurations_not_evaluated' => $summary['not_evaluated'],
                'commercially_ready' => 0,
                'commercially_approved' => 0,
                'database_writes' => 0,
                'variants_created' => 0,
            ],
            'supplier_submission_status' => $validated['evidence_complete'] > 0
                ? 'SUPPLIER_DATA_PRESENT_UNAPPROVED' : 'AWAITING_SUPPLIER_DATA',
            'gate' => 'PASS',
        ];
    }

    private function same(string $name, mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) throw new RuntimeException('Pipeline replay mismatch: '.$name);
    }

    private function validateCsvHeader(string $csv): void
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new RuntimeException('CSV buffer unavailable.');
        try {
            fwrite($stream, $csv);
            rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (!is_array($header)) throw new RuntimeException('Missing CSV header.');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
            if ($header !== QmedSupplierResponseValidator::HEADERS) throw new RuntimeException('CSV header mismatch.');
        } finally {
            fclose($stream);
        }
    }
}
