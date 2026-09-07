<?php

declare(strict_types=1);

namespace App\Services\Antar;

use App\Enums\VatRate;
use JsonException;
use RuntimeException;

final class AntarSupplierPriceList
{
    public const SOURCE_XLSX_SHA256 = 'c50f14f66293fe6e91ae4b558413217241acda84cb421a554392f4ddda081ea9';

    public const REFERENCE_JSON_SHA256 = '1c66f7c7a241c51302d243451d86a7a144088135d7a11c810d1a79284f6ecbc7';

    public const EFFECTIVE_FROM = '2026-09-01';

    private const REFERENCE_RELATIVE_PATH = 'import-data/antar/price-list-2026-09-01.json';

    /**
     * @return array{
     *     metadata: array<string, mixed>,
     *     rows: list<array<string, mixed>>,
     *     safe_index: array<string, array<string, mixed>>,
     *     ambiguous_index: array<string, array<string, mixed>>,
     *     summary: array<string, mixed>
     * }
     */
    public function load(?string $path = null): array
    {
        $path ??= resource_path(self::REFERENCE_RELATIVE_PATH);
        $raw = @file_get_contents($path);

        if (! is_string($raw)) {
            throw new RuntimeException('Antar supplier price reference JSON cannot be read: '.$path);
        }

        $referenceSha = hash('sha256', $raw);

        if (! hash_equals(self::REFERENCE_JSON_SHA256, $referenceSha)) {
            throw new RuntimeException(
                'Antar supplier price reference JSON SHA-256 mismatch: expected '
                .self::REFERENCE_JSON_SHA256.', actual '.$referenceSha.'.',
            );
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid Antar supplier price reference JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Antar supplier price reference JSON must decode to an object.');
        }

        $this->validateMetadata($decoded);

        $rows = $this->records($decoded['rows'] ?? []);
        $grouped = [];
        $vatBreakdown = [];

        foreach ($rows as $position => $row) {
            $code = $this->normalizeCode($row['normalized_code'] ?? $row['code'] ?? null);
            $netMinor = $this->positiveIntOrNull($row['net_minor'] ?? null);
            $grossMinor = $this->positiveIntOrNull($row['gross_minor'] ?? null);
            $vatRate = $this->vatRateOrNull($row['vat_rate'] ?? null);
            $sourceRow = $this->positiveIntOrNull($row['source_row'] ?? null);
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));

            if ($code === null || $netMinor === null || $grossMinor === null || $vatRate === null || $sourceRow === null || $currency !== 'PLN') {
                throw new RuntimeException('Invalid Antar supplier price row at reference position '.($position + 1).'.');
            }

            if ($vatRate->grossFromNet($netMinor) !== $grossMinor) {
                throw new RuntimeException('Antar supplier price row '.$sourceRow.' has inconsistent net/gross/VAT arithmetic.');
            }

            $normalized = $row;
            $normalized['normalized_code'] = $code;
            $normalized['net_minor'] = $netMinor;
            $normalized['gross_minor'] = $grossMinor;
            $normalized['vat_rate'] = $vatRate->value;
            $normalized['source_row'] = $sourceRow;
            $normalized['currency'] = 'PLN';
            $grouped[$code][] = $normalized;
            $vatBreakdown[$vatRate->value] = ($vatBreakdown[$vatRate->value] ?? 0) + 1;
        }

        if (count($rows) !== 995) {
            throw new RuntimeException('Antar supplier price row count mismatch: expected 995, actual '.count($rows).'.');
        }

        ksort($vatBreakdown);

        if ($vatBreakdown !== [5 => 4, 8 => 947, 23 => 44]) {
            throw new RuntimeException('Antar supplier VAT row breakdown does not match the frozen 1 September 2026 price list.');
        }

        $safeIndex = [];
        $ambiguousIndex = [];
        $consistentDuplicateCodes = 0;

        foreach ($grouped as $code => $codeRows) {
            $priceKeys = [];

            foreach ($codeRows as $row) {
                $priceKeys[$row['net_minor'].'|'.$row['gross_minor'].'|'.$row['vat_rate'].'|'.$row['currency']] = true;
            }

            if (count($priceKeys) > 1) {
                $ambiguousIndex[$code] = [
                    'normalized_code' => $code,
                    'source_rows' => array_values(array_map(
                        static fn (array $row): int => (int) $row['source_row'],
                        $codeRows,
                    )),
                    'prices' => array_values(array_map(
                        static fn (array $row): array => [
                            'net_minor' => (int) $row['net_minor'],
                            'gross_minor' => (int) $row['gross_minor'],
                            'vat_rate' => (int) $row['vat_rate'],
                            'currency' => (string) $row['currency'],
                            'description' => is_string($row['description'] ?? null) ? trim($row['description']) : null,
                            'option' => is_string($row['option'] ?? null) ? trim($row['option']) : null,
                        ],
                        $codeRows,
                    )),
                ];

                continue;
            }

            if (count($codeRows) > 1) {
                $consistentDuplicateCodes++;
            }

            $first = $codeRows[0];
            $safeIndex[$code] = [
                'normalized_code' => $code,
                'supplier_code' => (string) ($first['code'] ?? $code),
                'net_minor' => (int) $first['net_minor'],
                'gross_minor' => (int) $first['gross_minor'],
                'vat_rate' => (int) $first['vat_rate'],
                'currency' => 'PLN',
                'source_rows' => array_values(array_map(
                    static fn (array $row): int => (int) $row['source_row'],
                    $codeRows,
                )),
                'descriptions' => array_values(array_unique(array_filter(array_map(
                    static fn (array $row): ?string => is_string($row['description'] ?? null)
                        ? trim($row['description'])
                        : null,
                    $codeRows,
                )))),
            ];
        }

        ksort($safeIndex);
        ksort($ambiguousIndex);

        if (count($grouped) !== 940 || count($safeIndex) !== 930 || count($ambiguousIndex) !== 10 || $consistentDuplicateCodes !== 19) {
            throw new RuntimeException('Antar supplier price grouping counts do not match the frozen 1 September 2026 price list.');
        }

        return [
            'metadata' => [
                'source' => $decoded['source'],
                'source_file' => $decoded['source_file'],
                'source_sha256' => $decoded['source_sha256'],
                'reference_json_sha256' => $referenceSha,
                'effective_from' => $decoded['effective_from'],
                'worksheet' => $decoded['worksheet'],
                'header_row' => $decoded['header_row'],
                'price_net_column' => $decoded['price_net_column'],
                'vat_column' => $decoded['vat_column'],
                'price_gross_column' => $decoded['price_gross_column'],
                'currency' => $decoded['currency'],
            ],
            'rows' => $rows,
            'safe_index' => $safeIndex,
            'ambiguous_index' => $ambiguousIndex,
            'summary' => [
                'rows' => count($rows),
                'unique_normalized_codes' => count($grouped),
                'safe_price_codes' => count($safeIndex),
                'ambiguous_price_codes' => count($ambiguousIndex),
                'consistent_duplicate_codes' => $consistentDuplicateCodes,
                'vat_row_breakdown' => $vatBreakdown,
                'gross_formula_mismatches' => 0,
            ],
        ];
    }

    public function normalizeCode(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $code = html_entity_decode(trim((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($code === '') {
            return null;
        }

        $code = str_replace(['/', '\\'], '-', $code);
        $code = preg_replace('/\s+/', '-', $code) ?? $code;
        $code = preg_replace('/[^A-Za-z0-9._-]+/', '-', $code) ?? $code;
        $code = preg_replace('/-+/', '-', $code) ?? $code;
        $code = strtoupper(trim($code, '-._'));

        return $code === '' ? null : $code;
    }

    /** @param array<string, mixed> $decoded */
    private function validateMetadata(array $decoded): void
    {
        $checks = [
            'source' => 'antar_supplier_price_list',
            'source_file' => 'A CENNIK ANTAR PELNY od 2026 09 01.xlsx',
            'source_sha256' => self::SOURCE_XLSX_SHA256,
            'effective_from' => self::EFFECTIVE_FROM,
            'worksheet' => 'Cennik',
            'header_row' => 81,
            'price_net_column' => 'E',
            'vat_column' => 'F',
            'price_gross_column' => 'G',
            'currency' => 'PLN',
            'row_count' => 995,
            'unique_normalized_code_count' => 940,
            'safe_price_code_count' => 930,
            'ambiguous_price_code_count' => 10,
            'consistent_duplicate_code_count' => 19,
            'gross_formula_mismatch_count' => 0,
        ];

        foreach ($checks as $key => $expected) {
            if (($decoded[$key] ?? null) !== $expected) {
                throw new RuntimeException('Antar supplier price reference metadata mismatch for '.$key.'.');
            }
        }

        if (($decoded['vat_row_breakdown'] ?? null) !== ['5' => 4, '8' => 947, '23' => 44]) {
            throw new RuntimeException('Antar supplier price reference VAT metadata does not match the frozen source.');
        }
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function vatRateOrNull(mixed $value): ?VatRate
    {
        if (! is_numeric($value)) {
            return null;
        }

        return VatRate::tryFrom((int) $value);
    }

    /** @return list<array<string, mixed>> */
    private function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
