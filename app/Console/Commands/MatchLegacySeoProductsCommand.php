<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;

class MatchLegacySeoProductsCommand extends Command
{
    protected $signature = 'seo:match-legacy-products
        {--legacy-csv=scrapers/seo/ortezka/matching/legacy-inventory.csv : Final SEO-01 legacy inventory CSV on the local disk}
        {--target-products-csv=scrapers/seo/ortezka/matching/production-target-products.csv : Production SEO-02A product inventory CSV on the local disk}
        {--save=scrapers/seo/ortezka/matching/product-match-evidence.json : JSON evidence report on the local disk}
        {--csv=scrapers/seo/ortezka/matching/product-match-evidence.csv : CSV evidence report on the local disk}';

    protected $description = 'Build a read-only evidence report matching live legacy Ortezka products to Konji product candidates.';

    /** @var array<string, int> */
    private array $classificationCounts = [];

    public function handle(): int
    {
        $this->info('Building the read-only legacy-to-Konji product match evidence report.');
        $this->line('Database writes: NO');
        $this->line('Redirect/runtime changes: NO');
        $this->line('Redirects approved by this command: 0');

        try {
            $legacyPath = $this->inputPath('legacy-csv');
            $targetPath = $this->inputPath('target-products-csv');
            $jsonPath = $this->outputPath('save');
            $csvPath = $this->outputPath('csv');
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! is_file($legacyPath)) {
            $this->error('Legacy inventory CSV does not exist: '.$legacyPath);

            return self::FAILURE;
        }

        if (! is_file($targetPath)) {
            $this->error('Target product inventory CSV does not exist: '.$targetPath);

            return self::FAILURE;
        }

        try {
            $legacyProducts = $this->loadLiveLegacyProducts($legacyPath);
            $legacyIndexCounts = $this->countLegacyKeys($legacyProducts, 'index', fn (?string $value): ?string => $this->matchingKey($value));
            $legacyNameCounts = $this->countLegacyKeys($legacyProducts, 'h1', fn (?string $value): ?string => $this->nameKey($value));
            $target = $this->loadTargetInventory($targetPath);

            $this->ensureParentDirectory($jsonPath);
            $this->ensureParentDirectory($csvPath);

            $csv = fopen($csvPath, 'wb');
            $json = fopen($jsonPath, 'wb');

            if ($csv === false || $json === false) {
                throw new RuntimeException('Unable to open SEO matching output files for writing.');
            }

            $headers = $this->csvHeaders();
            fputcsv($csv, $headers);
            fwrite($json, "{\n  \"schema_version\": 1,\n  \"redirects_approved\": 0,\n  \"matches\": [\n");

            $first = true;
            $records = 0;
            $candidateRecords = 0;
            $reachableCandidates = 0;
            $highConfidenceEvidence = 0;

            ksort($legacyProducts, SORT_NATURAL);

            foreach ($legacyProducts as $legacyId => $legacy) {
                $record = $this->buildEvidenceRecord(
                    $legacyId,
                    $legacy,
                    $legacyIndexCounts,
                    $legacyNameCounts,
                    $target,
                );

                $classification = $record['classification'];
                $this->classificationCounts[$classification] = ($this->classificationCounts[$classification] ?? 0) + 1;

                if ($record['candidate_product_id'] !== null) {
                    $candidateRecords++;
                }

                if ($record['candidate_storefront_reachable'] === true) {
                    $reachableCandidates++;
                }

                if ($classification === 'exact_identifier_and_name') {
                    $highConfidenceEvidence++;
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

            $summary = [
                'live_canonical_legacy_products' => $records,
                'candidate_records' => $candidateRecords,
                'candidate_storefront_reachable' => $reachableCandidates,
                'exact_identifier_and_name' => $highConfidenceEvidence,
                'redirects_approved' => 0,
                'classifications' => $this->classificationCounts,
            ];

            fwrite($json, "\n  ],\n  \"summary\": ".json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n}\n");
            fclose($csv);
            fclose($json);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Legacy SEO product matching evidence summary.');
        $this->line('Live canonical legacy products: '.$summary['live_canonical_legacy_products']);
        $this->line('Candidate records: '.$summary['candidate_records']);
        $this->line('Candidate storefront-reachable targets: '.$summary['candidate_storefront_reachable']);
        $this->line('Exact identifier + exact name evidence: '.$summary['exact_identifier_and_name']);
        $this->line('Redirects approved: 0');

        foreach ($summary['classifications'] as $classification => $count) {
            $this->line($classification.': '.$count);
        }

        $this->line('JSON evidence: '.$jsonPath);
        $this->line('CSV evidence: '.$csvPath);

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function loadLiveLegacyProducts(string $path): array
    {
        $file = new SplFileObject($path, 'rb');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $header = $file->fgetcsv();

        if (! is_array($header)) {
            throw new RuntimeException('Legacy inventory CSV is missing a header row.');
        }

        $header = array_map(fn (mixed $value): string => (string) $value, $header);
        $required = ['url', 'path', 'type', 'legacy_id', 'status', 'canonical', 'h1', 'index'];
        $this->assertHeaders($header, $required, 'legacy inventory');

        $products = [];

        while (! $file->eof()) {
            $values = $file->fgetcsv();

            if (! is_array($values) || $values === [null]) {
                continue;
            }

            $row = $this->combineRow($header, $values);

            if (($row['type'] ?? '') !== 'product' || ($row['status'] ?? '') !== '200') {
                continue;
            }

            $legacyId = trim((string) ($row['legacy_id'] ?? ''));

            if ($legacyId === '') {
                continue;
            }

            if (! isset($products[$legacyId]) || $this->isBetterCanonicalLegacyRow($row, $products[$legacyId])) {
                $products[$legacyId] = $row;
            }
        }

        return $products;
    }

    /**
     * @param  array<string, array<string, string>>  $legacyProducts
     * @param  callable(?string): ?string  $normalizer
     * @return array<string, int>
     */
    private function countLegacyKeys(array $legacyProducts, string $field, callable $normalizer): array
    {
        $counts = [];

        foreach ($legacyProducts as $product) {
            $key = $normalizer($product[$field] ?? null);

            if ($key === null) {
                continue;
            }

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array{
     *   products: array<string, array<string, mixed>>,
     *   sku: array<string, array<string, array<string, string>>>,
     *   parent: array<string, array<string, true>>,
     *   name: array<string, array<string, true>>
     * }
     */
    private function loadTargetInventory(string $path): array
    {
        $file = new SplFileObject($path, 'rb');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $header = $file->fgetcsv();

        if (! is_array($header)) {
            throw new RuntimeException('Target product inventory CSV is missing a header row.');
        }

        $header = array_map(fn (mixed $value): string => (string) $value, $header);
        $required = [
            'product_id', 'product_name', 'target_path', 'product_status', 'storefront_reachable',
            'external_source', 'external_parent_sku', 'external_parent_sku_matching_key',
            'variant_id', 'variant_sku', 'variant_sku_matching_key', 'variant_status',
        ];
        $this->assertHeaders($header, $required, 'target product inventory');

        $products = [];
        $sku = [];
        $parent = [];
        $name = [];

        while (! $file->eof()) {
            $values = $file->fgetcsv();

            if (! is_array($values) || $values === [null]) {
                continue;
            }

            $row = $this->combineRow($header, $values);
            $productId = trim((string) ($row['product_id'] ?? ''));

            if ($productId === '') {
                continue;
            }

            if (! isset($products[$productId])) {
                $products[$productId] = [
                    'product_id' => $productId,
                    'product_name' => (string) ($row['product_name'] ?? ''),
                    'target_path' => (string) ($row['target_path'] ?? ''),
                    'product_status' => (string) ($row['product_status'] ?? ''),
                    'storefront_reachable' => ($row['storefront_reachable'] ?? '') === '1',
                    'external_source' => (string) ($row['external_source'] ?? ''),
                    'external_parent_sku' => (string) ($row['external_parent_sku'] ?? ''),
                    'active_variant_count' => 0,
                    'variant_count' => 0,
                ];

                $nameKey = $this->nameKey($row['product_name'] ?? null);

                if ($nameKey !== null) {
                    $name[$nameKey][$productId] = true;
                }

                $parentKey = $this->matchingKey($row['external_parent_sku_matching_key'] ?? null);

                if ($parentKey !== null) {
                    $parent[$parentKey][$productId] = true;
                }
            }

            if (trim((string) ($row['variant_id'] ?? '')) !== '') {
                $products[$productId]['variant_count']++;

                if (($row['variant_status'] ?? '') === 'active') {
                    $products[$productId]['active_variant_count']++;
                }
            }

            $skuKey = $this->matchingKey($row['variant_sku_matching_key'] ?? null);

            if ($skuKey !== null) {
                $variantId = trim((string) ($row['variant_id'] ?? ''));

                if ($variantId !== '') {
                    $sku[$skuKey][$variantId] = [
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'variant_sku' => (string) ($row['variant_sku'] ?? ''),
                        'variant_status' => (string) ($row['variant_status'] ?? ''),
                    ];
                }
            }
        }

        return compact('products', 'sku', 'parent', 'name');
    }

    /**
     * @param  array<string, string>  $legacy
     * @param  array<string, int>  $legacyIndexCounts
     * @param  array<string, int>  $legacyNameCounts
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function buildEvidenceRecord(
        string $legacyId,
        array $legacy,
        array $legacyIndexCounts,
        array $legacyNameCounts,
        array $target,
    ): array {
        $indexKey = $this->matchingKey($legacy['index'] ?? null);
        $nameKey = $this->nameKey($legacy['h1'] ?? null);
        $indexUnique = $indexKey !== null && ($legacyIndexCounts[$indexKey] ?? 0) === 1;
        $nameUnique = $nameKey !== null && ($legacyNameCounts[$nameKey] ?? 0) === 1;

        $variantCandidateRow = $indexUnique ? $this->singleVariantCandidate($target['sku'][$indexKey] ?? []) : null;
        $variantPid = $variantCandidateRow['product_id'] ?? null;
        $parentPid = $indexUnique ? $this->singleProductId($target['parent'][$indexKey] ?? []) : null;
        $namePid = $nameUnique ? $this->singleProductId($target['name'][$nameKey] ?? []) : null;

        $candidatePid = null;
        $classification = 'unmatched';

        if ($variantPid !== null && $parentPid !== null && $variantPid !== $parentPid) {
            $classification = 'identifier_conflict';
        } elseif ($namePid !== null && (($variantPid !== null && $namePid === $variantPid) || ($parentPid !== null && $namePid === $parentPid))) {
            $classification = 'exact_identifier_and_name';
            $candidatePid = $namePid;
        } elseif ($variantPid !== null && $parentPid !== null && $variantPid === $parentPid) {
            $classification = 'identifier_agreement';
            $candidatePid = $variantPid;
        } elseif ($parentPid !== null) {
            $classification = 'parent_candidate';
            $candidatePid = $parentPid;
        } elseif ($variantPid !== null) {
            $classification = 'variant_candidate';
            $candidatePid = $variantPid;
        } elseif ($namePid !== null) {
            $classification = 'exact_name_only';
            $candidatePid = $namePid;
        } elseif ($indexKey === null) {
            $classification = 'missing_legacy_index';
        } elseif (! $indexUnique) {
            $classification = 'duplicate_legacy_index';
        }

        $candidate = $candidatePid !== null ? ($target['products'][$candidatePid] ?? null) : null;
        $variantCandidate = $variantPid !== null ? ($target['products'][$variantPid] ?? null) : null;
        $parentCandidate = $parentPid !== null ? ($target['products'][$parentPid] ?? null) : null;
        $nameCandidate = $namePid !== null ? ($target['products'][$namePid] ?? null) : null;

        return [
            'legacy_id' => $legacyId,
            'legacy_url' => $legacy['url'] ?? '',
            'legacy_path' => $legacy['path'] ?? '',
            'legacy_h1' => $legacy['h1'] ?? '',
            'legacy_index' => $legacy['index'] ?? '',
            'legacy_index_matching_key' => $indexKey,
            'legacy_index_unique' => $indexUnique,
            'legacy_name_matching_key' => $nameKey,
            'legacy_name_unique' => $nameUnique,
            'classification' => $classification,
            'redirect_approved' => false,
            'candidate_product_id' => $candidatePid,
            'candidate_product_name' => $candidate['product_name'] ?? null,
            'candidate_target_path' => $candidate['target_path'] ?? null,
            'candidate_product_status' => $candidate['product_status'] ?? null,
            'candidate_storefront_reachable' => $candidate['storefront_reachable'] ?? null,
            'candidate_active_variant_count' => $candidate['active_variant_count'] ?? null,
            'candidate_external_source' => $candidate['external_source'] ?? null,
            'candidate_external_parent_sku' => $candidate['external_parent_sku'] ?? null,
            'variant_candidate_product_id' => $variantPid,
            'variant_candidate_variant_id' => $variantCandidateRow['variant_id'] ?? null,
            'variant_candidate_variant_sku' => $variantCandidateRow['variant_sku'] ?? null,
            'variant_candidate_variant_status' => $variantCandidateRow['variant_status'] ?? null,
            'variant_candidate_product_name' => $variantCandidate['product_name'] ?? null,
            'variant_candidate_target_path' => $variantCandidate['target_path'] ?? null,
            'parent_candidate_product_id' => $parentPid,
            'parent_candidate_product_name' => $parentCandidate['product_name'] ?? null,
            'parent_candidate_target_path' => $parentCandidate['target_path'] ?? null,
            'name_candidate_product_id' => $namePid,
            'name_candidate_product_name' => $nameCandidate['product_name'] ?? null,
            'name_candidate_target_path' => $nameCandidate['target_path'] ?? null,
        ];
    }

    /** @param array<string, array<string, string>> $variants @return array<string, string>|null */
    private function singleVariantCandidate(array $variants): ?array
    {
        if (count($variants) !== 1) {
            return null;
        }

        $candidate = reset($variants);

        return is_array($candidate) ? $candidate : null;
    }

    /** @param array<string, true> $productIds */
    private function singleProductId(array $productIds): ?string
    {
        if (count($productIds) !== 1) {
            return null;
        }

        return (string) array_key_first($productIds);
    }

    /** @param array<string, string> $candidate @param array<string, string> $current */
    private function isBetterCanonicalLegacyRow(array $candidate, array $current): bool
    {
        $candidateCanonicalPath = $this->urlPath($candidate['canonical'] ?? null);
        $currentCanonicalPath = $this->urlPath($current['canonical'] ?? null);
        $candidatePath = (string) ($candidate['path'] ?? '');
        $currentPath = (string) ($current['path'] ?? '');

        $candidateExact = $candidateCanonicalPath !== null && $candidateCanonicalPath === $candidatePath;
        $currentExact = $currentCanonicalPath !== null && $currentCanonicalPath === $currentPath;

        if ($candidateExact !== $currentExact) {
            return $candidateExact;
        }

        return strcmp($candidatePath, $currentPath) < 0;
    }

    private function matchingKey(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $value);

        if (! is_string($collapsed) || $collapsed === '') {
            return null;
        }

        return mb_strtolower($collapsed, 'UTF-8');
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

    private function urlPath(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : null;
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
        $relative = $this->safeRelativeLocalPath($option);

        return Storage::disk('local')->path($relative);
    }

    private function outputPath(string $option): string
    {
        $relative = $this->safeRelativeLocalPath($option);

        return Storage::disk('local')->path($relative);
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
            'legacy_id', 'legacy_url', 'legacy_path', 'legacy_h1', 'legacy_index', 'legacy_index_matching_key',
            'legacy_index_unique', 'legacy_name_matching_key', 'legacy_name_unique', 'classification', 'redirect_approved',
            'candidate_product_id', 'candidate_product_name', 'candidate_target_path', 'candidate_product_status',
            'candidate_storefront_reachable', 'candidate_active_variant_count', 'candidate_external_source',
            'candidate_external_parent_sku', 'variant_candidate_product_id', 'variant_candidate_product_name',
            'variant_candidate_target_path', 'parent_candidate_product_id', 'parent_candidate_product_name',
            'parent_candidate_target_path', 'name_candidate_product_id', 'name_candidate_product_name',
            'name_candidate_target_path',
        ];
    }

    private function csvValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value;
    }
}
