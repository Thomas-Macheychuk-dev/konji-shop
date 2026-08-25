<?php

declare(strict_types=1);

namespace App\Services\Armedical;

use JsonException;
use RuntimeException;

final class ArmedicalSupplierPriceList
{
    public const SOURCE_XLS_SHA256 = 'ac97003ad885025e665961d05afe1ed2d74d88a53b4aa9b413896f292a282893';

    public const REFERENCE_JSON_SHA256 = '770c072b9258573c11961bd270e5c0475a175958388a260546390889d72eeab9';

    public const EFFECTIVE_FROM = '2026-03-04';

    private const REFERENCE_RELATIVE_PATH = 'import-data/armedical/price-list-2026-03-04.json';

    /**
     * @return array{
     *     metadata: array<string, mixed>,
     *     rows: list<array<string, mixed>>,
     *     index: array<string, array<string, mixed>>,
     *     summary: array<string, mixed>
     * }
     */
    public function load(?string $path = null): array
    {
        $path ??= resource_path(self::REFERENCE_RELATIVE_PATH);
        $raw = @file_get_contents($path);

        if (! is_string($raw)) {
            throw new RuntimeException('ARmedical supplier price reference JSON cannot be read: '.$path);
        }

        $referenceSha = hash('sha256', $raw);

        if (! hash_equals(self::REFERENCE_JSON_SHA256, $referenceSha)) {
            throw new RuntimeException(
                'ARmedical supplier price reference JSON SHA-256 mismatch: expected '
                .self::REFERENCE_JSON_SHA256.', actual '.$referenceSha.'.',
            );
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid ARmedical supplier price reference JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('ARmedical supplier price reference JSON must decode to an object.');
        }

        $this->validateMetadata($decoded);

        $rows = $this->records($decoded['rows'] ?? []);
        $grouped = [];
        $vatBreakdown = [];

        foreach ($rows as $position => $row) {
            $code = $this->normalizeCode($row['code'] ?? null);
            $netMinor = $this->positiveIntOrNull($row['net_minor'] ?? null);
            $vatRate = $this->vatRateOrNull($row['vat_rate'] ?? null);
            $sourceRow = $this->positiveIntOrNull($row['source_row'] ?? null);

            if ($code === null || $netMinor === null || $vatRate === null || $sourceRow === null) {
                throw new RuntimeException('Invalid ARmedical supplier price row at reference position '.($position + 1).'.');
            }

            $normalized = $row;
            $normalized['code'] = $code;
            $normalized['net_minor'] = $netMinor;
            $normalized['vat_rate'] = $vatRate;
            $normalized['source_row'] = $sourceRow;
            $grouped[$code][] = $normalized;
            $vatBreakdown[$vatRate] = ($vatBreakdown[$vatRate] ?? 0) + 1;
        }

        if (count($rows) !== 245) {
            throw new RuntimeException('ARmedical supplier price row count mismatch: expected 245, actual '.count($rows).'.');
        }

        ksort($vatBreakdown);

        if ($vatBreakdown !== [8 => 220, 23 => 25]) {
            throw new RuntimeException('ARmedical supplier VAT row breakdown does not match the frozen 2026 price list.');
        }

        $index = [];
        $duplicateCodeCount = 0;

        foreach ($grouped as $code => $codeRows) {
            $pairs = [];

            foreach ($codeRows as $row) {
                $pairs[$row['net_minor'].'|'.$row['vat_rate']] = true;
            }

            if (count($pairs) !== 1) {
                throw new RuntimeException('ARmedical supplier price code '.$code.' has conflicting net/VAT values.');
            }

            if (count($codeRows) > 1) {
                $duplicateCodeCount++;
            }

            $first = $codeRows[0];
            $index[$code] = [
                'code' => $code,
                'net_minor' => (int) $first['net_minor'],
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

        ksort($index);

        if (count($index) !== 241) {
            throw new RuntimeException('ARmedical supplier unique price-code count mismatch: expected 241, actual '.count($index).'.');
        }

        return [
            'metadata' => [
                'source' => $decoded['source'],
                'source_file' => $decoded['source_file'],
                'source_sha256' => $decoded['source_sha256'],
                'reference_json_sha256' => $referenceSha,
                'effective_from' => $decoded['effective_from'],
                'worksheet' => $decoded['worksheet'],
                'price_column' => $decoded['price_column'],
                'vat_column' => $decoded['vat_column'],
                'ignored_price_column' => $decoded['ignored_price_column'],
                'currency' => $decoded['currency'],
            ],
            'rows' => $rows,
            'index' => $index,
            'summary' => [
                'rows' => count($rows),
                'unique_codes' => count($index),
                'duplicate_codes_with_consistent_price' => $duplicateCodeCount,
                'vat_row_breakdown' => $vatBreakdown,
            ],
        ];
    }

    /** @param array<string, mixed> $decoded */
    private function validateMetadata(array $decoded): void
    {
        $checks = [
            'source' => 'armedical_supplier_price_list',
            'source_file' => 'Armedical_Cennik_na_2026_aktualny_od_04.03.2026 (1).xls',
            'source_sha256' => self::SOURCE_XLS_SHA256,
            'effective_from' => self::EFFECTIVE_FROM,
            'worksheet' => 'Arkusz1',
            'price_column' => 'Cena netto',
            'vat_column' => 'VAT %',
            'ignored_price_column' => 'Pakiet 5+1 cena*',
            'currency' => 'PLN',
        ];

        foreach ($checks as $key => $expected) {
            if (($decoded[$key] ?? null) !== $expected) {
                throw new RuntimeException('ARmedical supplier price reference metadata mismatch for '.$key.'.');
            }
        }

        if (($decoded['row_count'] ?? null) !== 245 || ($decoded['unique_code_count'] ?? null) !== 241) {
            throw new RuntimeException('ARmedical supplier price reference metadata counts do not match the frozen source.');
        }
    }

    private function normalizeCode(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = strtoupper(trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function vatRateOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return in_array($value, [8, 23], true) ? $value : null;
    }

    /** @return list<array<string, mixed>> */
    private function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
