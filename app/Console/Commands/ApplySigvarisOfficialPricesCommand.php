<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sigvaris\SigvarisOfficialPricePlanner;
use App\Services\Sigvaris\SigvarisOfficialPriceWriter;
use Illuminate\Console\Command;
use JsonException;

final class ApplySigvarisOfficialPricesCommand extends Command
{
    private const WRITE_CONFIRMATION = 'APPLY-SIGVARIS-OFFICIAL-PRICES';

    private const UNMATCHED_ACKNOWLEDGEMENT = 'LEAVE-5-UNMATCHED-SIGVARIS-PRODUCTS-UNCHANGED';

    protected $signature = 'sigvaris:apply-official-prices
        {--plan=scrapers/sigvaris/official-price-plan-2026-01-21.json : Read-only official price-plan JSON. Relative paths resolve under storage/app.}
        {--expected-plan-sha256= : Exact SHA-256 of the generated official price-plan JSON. Required.}
        {--write : Apply the 14,964 deterministic variant prices. Local/testing only.}
        {--confirm-write= : Exact write confirmation phrase.}
        {--acknowledge-unmatched= : Exact acknowledgement that the five unresolved products remain unchanged.}
        {--save=scrapers/sigvaris/official-price-write-local.json : Save dry-run/write evidence JSON. Empty value skips saving.}
        {--show-unmatched : Print the five deliberately unchanged products.}';

    protected $description = 'Preflight and locally apply official Sigvaris 2026 prices to only the deterministically matched variants.';

    public function __construct(
        private readonly SigvarisOfficialPriceWriter $writer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $planPath = $this->resolvePath((string) $this->option('plan'));
        $expectedPlanSha = $this->normalizedSha256((string) ($this->option('expected-plan-sha256') ?? ''));
        $write = (bool) $this->option('write');

        $this->info('Sigvaris official price application');
        $this->line('Mode: '.($write ? 'LOCAL WRITE' : 'READ-ONLY PREFLIGHT'));
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

        if (! is_string($actualPlanSha)) {
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

        $this->newLine();
        $this->info('=== SIGVARIS OFFICIAL PRICE PREFLIGHT ===');
        $this->line('Catalogue products: '.($metrics['catalogue_products'] ?? 0).'/226');
        $this->line('Catalogue variants: '.($metrics['catalogue_variants'] ?? 0).'/14991');
        $this->line('Matched products: '.($metrics['matched_products'] ?? 0).'/221');
        $this->line('Matched variants: '.($metrics['matched_variants'] ?? 0).'/14964');
        $this->line('Unmatched products left unchanged: '.($metrics['unmatched_products'] ?? 0).'/5');
        $this->line('Unmatched variants left unchanged: '.($metrics['unmatched_variants'] ?? 0).'/27');
        $this->line('Variants requiring price/VAT change: '.($metrics['variants_to_change'] ?? 0));
        $this->line('Variants already correct: '.($metrics['variants_already_correct'] ?? 0));
        $this->line('VAT changes required: '.($metrics['vat_changes'] ?? 0));
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

        if (! ($preflight['passed'] ?? false)) {
            $this->saveEvidence($plan, $actualPlanSha, $preflight, null);
            $this->error('FAIL: official Sigvaris price preflight failed. No prices were changed.');
            return self::FAILURE;
        }

        if (! $write) {
            $this->saveEvidence($plan, $actualPlanSha, $preflight, null);
            $this->info('PASS: read-only price-write preflight passed. No catalogue writes were performed.');
            return self::SUCCESS;
        }

        if (! app()->environment(['local', 'testing'])) {
            $this->error('BLOCKED: this command intentionally permits writes only in local/testing. Use the separate production execution gate after local validation.');
            return self::FAILURE;
        }

        if ((string) ($this->option('confirm-write') ?? '') !== self::WRITE_CONFIRMATION) {
            $this->error('BLOCKED: --confirm-write must equal '.self::WRITE_CONFIRMATION.'.');
            return self::FAILURE;
        }

        if ((string) ($this->option('acknowledge-unmatched') ?? '') !== self::UNMATCHED_ACKNOWLEDGEMENT) {
            $this->error('BLOCKED: explicitly acknowledge that the five unmatched products remain unchanged with --acknowledge-unmatched='.self::UNMATCHED_ACKNOWLEDGEMENT.'.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('=== PRICE WRITE PROGRESS ===');

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
        } catch (\Throwable $exception) {
            $this->error('Price write failed: '.$exception->getMessage());
            return self::FAILURE;
        }

        $audit = $result['post_audit'] ?? [];
        $auditMetrics = $audit['metrics'] ?? [];

        $this->newLine();
        $this->info('=== SIGVARIS OFFICIAL PRICE WRITE RESULT ===');
        $this->line('Variants updated: '.($result['variants_updated'] ?? 0));
        $this->line('Variants unchanged: '.($result['variants_unchanged'] ?? 0));
        $this->line('Planned prices verified: '.($auditMetrics['planned_variants_verified'] ?? 0).'/14964');
        $this->line('Unmatched variants preserved: '.($auditMetrics['unmatched_variants_verified_unchanged'] ?? 0).'/27');
        $this->line('Post-write audit errors: '.count($audit['errors'] ?? []));

        foreach (($audit['errors'] ?? []) as $error) {
            $this->line('- '.$error);
        }

        $this->saveEvidence($plan, $actualPlanSha, $preflight, $result);

        if (! ($result['passed'] ?? false) || ! ($audit['passed'] ?? false)) {
            $this->error('FAIL: Sigvaris price write did not pass the post-write audit.');
            return self::FAILURE;
        }

        $this->info('PASS: 14,964 deterministic Sigvaris variant prices are aligned to the official 21 January 2026 lists; the 27 unresolved variants were untouched.');

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

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $preflight
     * @param array<string, mixed>|null $result
     */
    private function saveEvidence(array $plan, string $planSha, array $preflight, ?array $result): void
    {
        $save = trim((string) ($this->option('save') ?? ''));
        if ($save === '') {
            return;
        }

        $path = $this->resolvePath($save);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->warn('Unable to create evidence directory: '.$directory);
            return;
        }

        $evidence = [
            'source' => 'sigvaris',
            'price_plan_sha256' => $planSha,
            'import_map_sha256' => $plan['import_map_sha256'] ?? null,
            'price_list_effective_date' => $plan['price_list_effective_date'] ?? null,
            'markup_percent' => $plan['markup_percent'] ?? null,
            'executed_at' => now()->toIso8601String(),
            'write_requested' => (bool) $this->option('write'),
            'preflight' => $preflight,
            'result' => $result,
        ];

        try {
            $json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->warn('Unable to encode evidence JSON: '.$exception->getMessage());
            return;
        }

        if (! is_string($json) || file_put_contents($path, $json.PHP_EOL) === false) {
            $this->warn('Unable to save evidence JSON: '.$path);
            return;
        }

        $this->line('Saved evidence JSON to '.$path);
    }

    private function normalizedSha256(string $value): ?string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }
}
