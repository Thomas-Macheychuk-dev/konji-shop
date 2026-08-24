<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sigvaris\SigvarisOfficialPricePlanner;
use App\Services\Sigvaris\SigvarisOfficialPriceWriter;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class ExecuteSigvarisOfficialPriceProductionCommand extends Command
{
    private const WRITE_CONFIRMATION = 'APPLY-SIGVARIS-OFFICIAL-PRICES-PRODUCTION';

    private const UNMATCHED_ACKNOWLEDGEMENT = 'LEAVE-5-UNMATCHED-SIGVARIS-PRODUCTS-UNCHANGED';

    protected $signature = 'sigvaris:production-apply-official-prices
        {--plan=scrapers/sigvaris/official-price-plan-2026-01-21.json : Read-only official price-plan JSON. Relative paths resolve under storage/app.}
        {--expected-plan-sha256= : Exact SHA-256 of the generated official price-plan JSON. Required.}
        {--expected-current-price-sha256= : Exact pre-write fingerprint of all 14,991 current Sigvaris variant prices. Required for writes.}
        {--write : Apply the 14,964 deterministic prices in production. Dry-run by default.}
        {--confirm-production-write= : Must equal APPLY-SIGVARIS-OFFICIAL-PRICES-PRODUCTION when --write is used.}
        {--acknowledge-unmatched= : Must explicitly acknowledge that the five unresolved products remain unchanged.}
        {--allow-non-production : Permit local/testing rehearsal. Production is required otherwise.}
        {--save-preflight=scrapers/sigvaris/official-price-production-preflight.json : Save preflight evidence JSON. Empty value skips saving.}
        {--save-audit=scrapers/sigvaris/official-price-production-audit.json : Save post-write evidence JSON. Empty value skips saving.}
        {--show-unmatched : Print the five deliberately unchanged products.}';

    protected $description = 'Guarded production application of the deterministic 2026 Sigvaris official prices with a whole-catalogue pre-write price fingerprint.';

    public function __construct(
        private readonly SigvarisOfficialPriceWriter $writer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment(['production', 'testing']) && ! (bool) $this->option('allow-non-production')) {
            $this->error('BLOCKED: this command is intended for production. Use --allow-non-production only for rehearsal.');

            return self::FAILURE;
        }

        $planPath = $this->resolvePath((string) $this->option('plan'));
        $expectedPlanSha = $this->normalizedSha256((string) ($this->option('expected-plan-sha256') ?? ''));
        $write = (bool) $this->option('write');

        $this->info('Sigvaris official production price execution');
        $this->line('Environment: '.app()->environment());
        $this->line('Mode: '.($write ? 'PRODUCTION WRITE' : 'READ-ONLY PRODUCTION PREFLIGHT'));
        $this->line('Network requests: NO');
        $this->line('Formula: (base net + (base net × VAT)) × 1.20');
        $this->line('Unmatched products remain unchanged: YES');
        $this->line('Plan: '.$planPath);

        if ($expectedPlanSha === null) {
            $this->error('BLOCKED: --expected-plan-sha256 must be a 64-character SHA-256 fingerprint.');

            return self::FAILURE;
        }

        if (! is_file($planPath) || ! is_readable($planPath)) {
            $this->error('Unable to read Sigvaris official price plan: '.$planPath);

            return self::FAILURE;
        }

        $actualPlanSha = hash_file('sha256', $planPath);

        if (! is_string($actualPlanSha) || $actualPlanSha === '') {
            $this->error('Unable to calculate official price-plan SHA-256.');

            return self::FAILURE;
        }

        $actualPlanSha = strtolower($actualPlanSha);
        $this->line('Price-plan SHA-256: '.$actualPlanSha);

        if (! hash_equals($expectedPlanSha, $actualPlanSha)) {
            $this->error('BLOCKED: official price-plan SHA-256 mismatch.');
            $this->line('Expected: '.$expectedPlanSha);
            $this->line('Actual:   '.$actualPlanSha);

            return self::FAILURE;
        }

        $plan = $this->loadJson($planPath);

        if ($plan === null) {
            return self::FAILURE;
        }

        if (($plan['import_map_sha256'] ?? null) !== SigvarisOfficialPricePlanner::IMPORT_MAP_SHA256) {
            $this->error('BLOCKED: price plan is not tied to the approved Sigvaris import map.');

            return self::FAILURE;
        }

        $preflight = $this->writer->preflight($plan);
        $metrics = $preflight['metrics'] ?? [];
        $currentPriceSha = $this->writer->cataloguePriceFingerprint();

        $this->newLine();
        $this->info('=== SIGVARIS PRODUCTION PRICE PREFLIGHT ===');
        $this->line('Catalogue products: '.($metrics['catalogue_products'] ?? 0).'/226');
        $this->line('Catalogue variants: '.($metrics['catalogue_variants'] ?? 0).'/14991');
        $this->line('Matched products: '.($metrics['matched_products'] ?? 0).'/221');
        $this->line('Matched variants: '.($metrics['matched_variants'] ?? 0).'/14964');
        $this->line('Unmatched products left unchanged: '.($metrics['unmatched_products'] ?? 0).'/5');
        $this->line('Unmatched variants left unchanged: '.($metrics['unmatched_variants'] ?? 0).'/27');
        $this->line('Variants requiring price/VAT change: '.($metrics['variants_to_change'] ?? 0));
        $this->line('Variants already correct: '.($metrics['variants_already_correct'] ?? 0));
        $this->line('VAT changes required: '.($metrics['vat_changes'] ?? 0));
        $this->line('Current catalogue price SHA-256: '.$currentPriceSha);
        $this->line('Preflight errors: '.count($preflight['errors'] ?? []));

        foreach (($preflight['errors'] ?? []) as $error) {
            $this->line('- '.$error);
        }

        if ((bool) $this->option('show-unmatched')) {
            $this->newLine();
            $this->warn('Products intentionally not changed:');
            foreach (($plan['unmatched_products'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $this->line(sprintf(
                    '- %s | %s | variants=%s',
                    (string) ($row['external_id'] ?? ''),
                    (string) ($row['name'] ?? ''),
                    (string) ($row['variant_count'] ?? ''),
                ));
            }
        }

        $preflightEvidence = [
            'mode' => $write ? 'production_write_requested' : 'read_only_production_preflight',
            'environment' => app()->environment(),
            'plan_path' => $planPath,
            'plan_sha256' => $actualPlanSha,
            'current_catalogue_price_sha256' => $currentPriceSha,
            'preflight' => $preflight,
        ];

        if (! $this->saveJsonOption('save-preflight', $preflightEvidence)) {
            return self::FAILURE;
        }

        if (! ($preflight['passed'] ?? false)) {
            $this->error('FAIL: production price preflight failed. No prices were changed.');

            return self::FAILURE;
        }

        if (! $write) {
            $this->info('PASS: read-only production price preflight passed. No catalogue writes were performed.');

            return self::SUCCESS;
        }

        $expectedCurrentPriceSha = $this->normalizedSha256((string) ($this->option('expected-current-price-sha256') ?? ''));

        if ($expectedCurrentPriceSha === null) {
            $this->error('BLOCKED: production writes require --expected-current-price-sha256=<64 hex characters> from the immediately preceding read-only preflight.');

            return self::FAILURE;
        }

        if (! hash_equals($expectedCurrentPriceSha, $currentPriceSha)) {
            $this->error('BLOCKED: current Sigvaris catalogue price fingerprint changed after preflight.');
            $this->line('Expected: '.$expectedCurrentPriceSha);
            $this->line('Actual:   '.$currentPriceSha);

            return self::FAILURE;
        }

        if ((string) ($this->option('confirm-production-write') ?? '') !== self::WRITE_CONFIRMATION) {
            $this->error('BLOCKED: --confirm-production-write must equal '.self::WRITE_CONFIRMATION.'.');

            return self::FAILURE;
        }

        if ((string) ($this->option('acknowledge-unmatched') ?? '') !== self::UNMATCHED_ACKNOWLEDGEMENT) {
            $this->error('BLOCKED: --acknowledge-unmatched must equal '.self::UNMATCHED_ACKNOWLEDGEMENT.'.');

            return self::FAILURE;
        }

        $this->warn('WRITE GATE PASSED: applying only the 14,964 deterministic official Sigvaris variant prices.');
        $this->info('=== PRODUCTION PRICE WRITE PROGRESS ===');

        try {
            $result = $this->writer->apply(
                $plan,
                function (int $processed, int $total, int $updated, int $unchanged): void {
                    $this->line(sprintf(
                        '[%s] %d/%d variants processed | updated=%d | unchanged=%d',
                        now()->format('H:i:s'),
                        $processed,
                        $total,
                        $updated,
                        $unchanged,
                    ));
                },
            );
        } catch (Throwable $exception) {
            $this->error('Production price write failed and was rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }

        $audit = $result['post_audit'] ?? [];
        $auditMetrics = $audit['metrics'] ?? [];
        $postPriceSha = $this->writer->cataloguePriceFingerprint();

        $this->newLine();
        $this->info('=== SIGVARIS PRODUCTION PRICE WRITE RESULT ===');
        $this->line('Variants updated: '.($result['variants_updated'] ?? 0));
        $this->line('Variants unchanged: '.($result['variants_unchanged'] ?? 0));
        $this->line('Planned prices verified: '.($auditMetrics['planned_variants_verified'] ?? 0).'/14964');
        $this->line('Unmatched variants preserved: '.($auditMetrics['unmatched_variants_verified_unchanged'] ?? 0).'/27');
        $this->line('Post-write catalogue price SHA-256: '.$postPriceSha);
        $this->line('Post-write audit errors: '.count($audit['errors'] ?? []));

        foreach (($audit['errors'] ?? []) as $error) {
            $this->line('- '.$error);
        }

        $auditEvidence = [
            'mode' => 'production_write',
            'environment' => app()->environment(),
            'plan_path' => $planPath,
            'plan_sha256' => $actualPlanSha,
            'pre_write_catalogue_price_sha256' => $currentPriceSha,
            'post_write_catalogue_price_sha256' => $postPriceSha,
            'preflight' => $preflight,
            'result' => $result,
        ];

        if (! $this->saveJsonOption('save-audit', $auditEvidence)) {
            return self::FAILURE;
        }

        if (! ($result['passed'] ?? false) || ! ($audit['passed'] ?? false)) {
            $this->error('FAIL: production Sigvaris price write did not pass the post-write audit.');

            return self::FAILURE;
        }

        $this->info('PASS: production Sigvaris prices now match the deterministic 21 January 2026 official price plan; 27 unresolved variants remained untouched.');

        return self::SUCCESS;
    }

    private function resolvePath(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return storage_path('app/scrapers/sigvaris/official-price-plan-2026-01-21.json');
        }

        return str_starts_with($value, '/') ? $value : storage_path('app/'.ltrim($value, '/'));
    }

    /** @return array<string, mixed>|null */
    private function loadJson(string $path): ?array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid JSON in official price plan: '.$exception->getMessage());

            return null;
        }

        if (! is_array($decoded)) {
            $this->error('Official price-plan root must be a JSON object.');

            return null;
        }

        return $decoded;
    }

    private function normalizedSha256(string $value): ?string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $payload */
    private function saveJsonOption(string $option, array $payload): bool
    {
        $value = trim((string) ($this->option($option) ?? ''));

        if ($value === '') {
            return true;
        }

        $path = str_starts_with($value, '/') ? $value : storage_path('app/'.ltrim($value, '/'));

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            $this->error('Unable to create evidence directory: '.dirname($path));

            return false;
        }

        try {
            $json = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            $this->error('Unable to encode evidence JSON: '.$exception->getMessage());

            return false;
        }

        if (file_put_contents($path, $json.PHP_EOL) === false) {
            $this->error('Unable to save evidence JSON: '.$path);

            return false;
        }

        $this->line('Saved evidence JSON to '.$path);

        return true;
    }
}
