<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;

class PrepareLegacySeoProductApprovalReviewCommand extends Command
{
    protected $signature = 'seo:prepare-legacy-product-approval-review
        {--evidence=scrapers/seo/ortezka/matching/product-match-evidence.json : SEO-02B JSON evidence on the local disk}
        {--legacy-csv=scrapers/seo/ortezka/matching/legacy-inventory.csv : Final SEO-01 legacy inventory CSV on the local disk}
        {--save=scrapers/seo/ortezka/matching/product-approval-review.json : JSON approval-review manifest on the local disk}
        {--csv=scrapers/seo/ortezka/matching/product-approval-review.csv : CSV approval-review manifest on the local disk}';

    protected $description = 'Prepare a read-only review manifest from SEO-02B evidence without approving or installing redirects.';

    /** @var array<string, int> */
    private array $reviewCounts = [];

    public function handle(): int
    {
        $this->info('Preparing the read-only legacy product redirect approval review manifest.');
        $this->line('Database writes: NO');
        $this->line('Redirect/runtime changes: NO');
        $this->line('Redirects approved by this command: 0');

        try {
            $evidencePath = $this->inputPath('evidence');
            $legacyPath = $this->inputPath('legacy-csv');
            $jsonPath = $this->outputPath('save');
            $csvPath = $this->outputPath('csv');
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! is_file($evidencePath)) {
            $this->error('SEO-02B evidence JSON does not exist: '.$evidencePath);

            return self::FAILURE;
        }

        if (! is_file($legacyPath)) {
            $this->error('Legacy inventory CSV does not exist: '.$legacyPath);

            return self::FAILURE;
        }

        try {
            $evidence = json_decode((string) file_get_contents($evidencePath), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($evidence) || ! isset($evidence['matches']) || ! is_array($evidence['matches'])) {
                throw new RuntimeException('SEO-02B evidence JSON has an unexpected structure.');
            }

            if ((int) ($evidence['redirects_approved'] ?? -1) !== 0) {
                throw new RuntimeException('SEO-02B evidence must contain redirects_approved=0.');
            }

            $legacySources = $this->loadLegacySources($legacyPath);
            $this->ensureParentDirectory($jsonPath);
            $this->ensureParentDirectory($csvPath);

            $csv = fopen($csvPath, 'wb');
            $json = fopen($jsonPath, 'wb');

            if ($csv === false || $json === false) {
                throw new RuntimeException('Unable to open SEO approval-review output files for writing.');
            }

            $headers = $this->csvHeaders();
            fputcsv($csv, $headers);
            fwrite($json, "{\n  \"schema_version\": 1,\n  \"redirects_approved\": 0,\n  \"approval_candidates\": 0,\n  \"source_evidence_sha256\": \"".hash_file('sha256', $evidencePath)."\",\n  \"records\": [\n");

            $first = true;
            $records = 0;
            $approvalCandidates = 0;
            $approvalCandidateSourcePaths = 0;

            foreach ($evidence['matches'] as $match) {
                if (! is_array($match)) {
                    throw new RuntimeException('SEO-02B evidence contains a non-object match record.');
                }

                $record = $this->buildReviewRecord($match, $legacySources);
                $reviewClass = $record['review_class'];
                $this->reviewCounts[$reviewClass] = ($this->reviewCounts[$reviewClass] ?? 0) + 1;

                if ($record['approval_candidate'] === true) {
                    $approvalCandidates++;
                    $approvalCandidateSourcePaths += (int) $record['legacy_source_path_count'];
                }

                fputcsv($csv, array_map(
                    fn (string $header): mixed => $this->csvValue($record[$header] ?? null),
                    $headers,
                ));

                $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                fwrite($json, ($first ? '' : ",\n").'    '.$encoded);
                $first = false;
                $records++;
            }

            ksort($this->reviewCounts);

            $summary = [
                'records' => $records,
                'approval_candidates' => $approvalCandidates,
                'approval_candidate_source_paths' => $approvalCandidateSourcePaths,
                'redirects_approved' => 0,
                'review_classes' => $this->reviewCounts,
            ];

            fwrite($json, "\n  ],\n  \"summary\": ".json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n}\n");
            fclose($csv);
            fclose($json);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Legacy SEO product approval-review summary.');
        $this->line('Legacy canonical products reviewed: '.$summary['records']);
        $this->line('Top-priority approval candidates: '.$summary['approval_candidates']);
        $this->line('Legacy source paths covered by top-priority candidates: '.$summary['approval_candidate_source_paths']);
        $this->line('Redirects approved: 0');

        foreach ($summary['review_classes'] as $reviewClass => $count) {
            $this->line($reviewClass.': '.$count);
        }

        $this->line('JSON review manifest: '.$jsonPath);
        $this->line('CSV review manifest: '.$csvPath);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array<string, array{paths: array<string, true>, statuses: array<string, true>}>  $legacySources
     * @return array<string, mixed>
     */
    private function buildReviewRecord(array $match, array $legacySources): array
    {
        $classification = (string) ($match['classification'] ?? '');
        $legacyId = (string) ($match['legacy_id'] ?? '');
        $candidateReachable = ($match['candidate_storefront_reachable'] ?? null) === true;
        $variantStatus = $match['variant_candidate_variant_status'] ?? null;
        $hasVariantCandidate = ($match['variant_candidate_product_id'] ?? null) !== null;
        $variantReady = ! $hasVariantCandidate || $variantStatus === 'active';

        $sharedTokens = $this->sharedSemanticTokens(
            (string) ($match['legacy_h1'] ?? ''),
            (string) ($match['candidate_product_name'] ?? ''),
            (string) ($match['legacy_index'] ?? ''),
        );

        $reviewClass = match ($classification) {
            'exact_identifier_and_name' => $candidateReachable && $variantReady
                ? 'approval_candidate_exact_identifier_and_name'
                : ($candidateReachable ? 'blocked_matched_variant_not_active' : 'blocked_target_draft_strong'),
            'identifier_agreement' => $candidateReachable && $variantReady
                ? ($sharedTokens === [] ? 'review_identifier_agreement_name_divergence' : 'review_identifier_agreement_semantic_support')
                : ($candidateReachable ? 'blocked_matched_variant_not_active' : 'blocked_target_draft_strong'),
            'parent_candidate' => $candidateReachable ? 'review_active_parent_candidate' : 'blocked_target_draft_weak',
            'variant_candidate' => $candidateReachable && $variantStatus === 'active'
                ? 'review_active_variant_candidate'
                : ($candidateReachable ? 'blocked_matched_variant_not_active' : 'blocked_target_draft_weak'),
            'exact_name_only' => $candidateReachable ? 'review_active_exact_name_only' : 'blocked_target_draft_weak',
            'identifier_conflict' => 'identifier_conflict',
            'duplicate_legacy_index' => 'duplicate_legacy_index',
            'missing_legacy_index' => 'missing_legacy_index',
            default => 'unmatched',
        };

        $sources = $legacySources[$legacyId] ?? ['paths' => [], 'statuses' => []];
        $sourcePaths = array_keys($sources['paths']);
        sort($sourcePaths, SORT_NATURAL);
        $sourceStatuses = array_keys($sources['statuses']);
        sort($sourceStatuses, SORT_NATURAL);

        return [
            'legacy_id' => $legacyId,
            'legacy_url' => $match['legacy_url'] ?? null,
            'legacy_path' => $match['legacy_path'] ?? null,
            'legacy_h1' => $match['legacy_h1'] ?? null,
            'legacy_index' => $match['legacy_index'] ?? null,
            'seo02b_classification' => $classification,
            'review_class' => $reviewClass,
            'approval_candidate' => $reviewClass === 'approval_candidate_exact_identifier_and_name',
            'redirect_approved' => false,
            'candidate_product_id' => $match['candidate_product_id'] ?? null,
            'candidate_product_name' => $match['candidate_product_name'] ?? null,
            'candidate_target_path' => $match['candidate_target_path'] ?? null,
            'candidate_product_status' => $match['candidate_product_status'] ?? null,
            'candidate_storefront_reachable' => $match['candidate_storefront_reachable'] ?? null,
            'candidate_active_variant_count' => $match['candidate_active_variant_count'] ?? null,
            'candidate_external_source' => $match['candidate_external_source'] ?? null,
            'candidate_external_parent_sku' => $match['candidate_external_parent_sku'] ?? null,
            'variant_candidate_product_id' => $match['variant_candidate_product_id'] ?? null,
            'variant_candidate_variant_id' => $match['variant_candidate_variant_id'] ?? null,
            'variant_candidate_variant_sku' => $match['variant_candidate_variant_sku'] ?? null,
            'variant_candidate_variant_status' => $variantStatus,
            'parent_candidate_product_id' => $match['parent_candidate_product_id'] ?? null,
            'name_candidate_product_id' => $match['name_candidate_product_id'] ?? null,
            'shared_semantic_token_count' => count($sharedTokens),
            'shared_semantic_tokens' => $sharedTokens,
            'legacy_source_path_count' => count($sourcePaths),
            'legacy_source_paths' => $sourcePaths,
            'legacy_source_statuses' => $sourceStatuses,
            'manual_review_required' => true,
        ];
    }

    /** @return array<int, string> */
    private function sharedSemanticTokens(string $legacyName, string $candidateName, string $legacyIndex): array
    {
        if ($candidateName === '') {
            return [];
        }

        $legacyTokens = $this->semanticTokens($legacyName, $legacyIndex);
        $candidateTokens = $this->semanticTokens($candidateName, $legacyIndex);
        $shared = array_values(array_intersect($legacyTokens, $candidateTokens));
        $shared = array_values(array_unique($shared));
        sort($shared, SORT_NATURAL);

        return $shared;
    }

    /** @return array<int, string> */
    private function semanticTokens(string $value, string $legacyIndex): array
    {
        $normalized = $this->nameKey($value);
        $index = $this->nameKey($legacyIndex);

        if ($normalized === null) {
            return [];
        }

        $indexTokens = $index === null ? [] : explode(' ', $index);
        $stop = ['antar', 'oppo', 'produkt', 'medyczny', 'medyczna', 'medyczne'];
        $tokens = [];

        foreach (explode(' ', $normalized) as $token) {
            if ($token === '' || strlen($token) < 3 || ctype_digit($token)) {
                continue;
            }

            if (in_array($token, $indexTokens, true) || in_array($token, $stop, true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    private function nameKey(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $ascii = mb_strtolower(Str::ascii($value), 'UTF-8');
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $ascii);

        if (! is_string($normalized)) {
            return null;
        }

        $normalized = trim((string) preg_replace('/\s+/u', ' ', $normalized));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, array{paths: array<string, true>, statuses: array<string, true>}>
     */
    private function loadLegacySources(string $path): array
    {
        $file = new SplFileObject($path, 'rb');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $header = $file->fgetcsv();

        if (! is_array($header)) {
            throw new RuntimeException('Legacy inventory CSV is missing a header row.');
        }

        $header = array_map(fn (mixed $value): string => (string) $value, $header);
        $this->assertHeaders($header, ['path', 'type', 'legacy_id', 'status'], 'legacy inventory');
        $sources = [];

        while (! $file->eof()) {
            $values = $file->fgetcsv();

            if (! is_array($values) || $values === [null]) {
                continue;
            }

            $row = $this->combineRow($header, $values);

            if (($row['type'] ?? '') !== 'product') {
                continue;
            }

            $legacyId = trim($row['legacy_id'] ?? '');
            $pathValue = trim($row['path'] ?? '');

            if ($legacyId === '' || $pathValue === '') {
                continue;
            }

            $sources[$legacyId] ??= ['paths' => [], 'statuses' => []];
            $sources[$legacyId]['paths'][$pathValue] = true;

            $status = trim($row['status'] ?? '');
            if ($status !== '') {
                $sources[$legacyId]['statuses'][$status] = true;
            }
        }

        return $sources;
    }

    /** @param array<int, string> $header @param array<int, mixed> $values @return array<string, string> */
    private function combineRow(array $header, array $values): array
    {
        $values = array_pad($values, count($header), '');
        $values = array_slice($values, 0, count($header));

        /** @var array<string, string> $row */
        $row = array_combine($header, array_map(fn (mixed $value): string => (string) $value, $values));

        return $row;
    }

    /** @param array<int, string> $header @param array<int, string> $required */
    private function assertHeaders(array $header, array $required, string $label): void
    {
        $missing = array_values(array_diff($required, $header));

        if ($missing !== []) {
            throw new RuntimeException(sprintf('%s CSV is missing required columns: %s', ucfirst($label), implode(', ', $missing)));
        }
    }

    private function inputPath(string $option): string
    {
        return Storage::disk('local')->path($this->safeRelativeLocalPath($option));
    }

    private function outputPath(string $option): string
    {
        return Storage::disk('local')->path($this->safeRelativeLocalPath($option));
    }

    private function safeRelativeLocalPath(string $option): string
    {
        $path = trim((string) $this->option($option));

        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new RuntimeException(sprintf('Option --%s must be a safe relative local-disk path.', $option));
        }

        return $path;
    }

    private function ensureParentDirectory(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create output directory: '.$directory);
        }
    }

    /** @return array<int, string> */
    private function csvHeaders(): array
    {
        return [
            'legacy_id', 'legacy_url', 'legacy_path', 'legacy_h1', 'legacy_index', 'seo02b_classification',
            'review_class', 'approval_candidate', 'redirect_approved', 'candidate_product_id', 'candidate_product_name',
            'candidate_target_path', 'candidate_product_status', 'candidate_storefront_reachable',
            'candidate_active_variant_count', 'candidate_external_source', 'candidate_external_parent_sku',
            'variant_candidate_product_id', 'variant_candidate_variant_id', 'variant_candidate_variant_sku',
            'variant_candidate_variant_status', 'parent_candidate_product_id', 'name_candidate_product_id',
            'shared_semantic_token_count', 'shared_semantic_tokens', 'legacy_source_path_count',
            'legacy_source_paths', 'legacy_source_statuses', 'manual_review_required',
        ];
    }

    private function csvValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return implode('|', array_map(fn (mixed $item): string => (string) $item, $value));
        }

        return $value;
    }
}
