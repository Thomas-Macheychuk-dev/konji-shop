<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use RuntimeException;

/** Read-only extraction of manufacturer sizing evidence. Never creates variants. */
final class QmedSizeClassifier
{
    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    public function analyse(array $data): array
    {
        if (!is_array($data['products'] ?? null) || !array_is_list($data['products'])) {
            throw new RuntimeException('Expected top-level products list.');
        }
    $normalise = static function (mixed $value): string {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }
        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    };

    $parseSize = static function (string $original, bool $allowDecimalSize = false) use ($normalise): ?array {
        $value = mb_strtoupper($normalise($original));
        $value = preg_replace('/\*+$/u', '', $value);
        $value = trim((string) $value);
        if (preg_match('/^(?:XXXS|XXS|XS|S|M|L|XL|XXL|XXXL|XXXXL|STANDARD|R0?[0-9]|[0-9]{1,2}|[0-9]{1,2}\s*XL|(?:XXS|XS|S|M|L|XL|XXL)\s*-\s*(?:XXS|XS|S|M|L|XL|XXL))$/uD', $value)) {
            return ['size' => preg_replace('/\s*-\s*/', '-', $value), 'flag' => $value !== $normalise($original) ? 'SIZE_LABEL_NORMALISED' : null];
        }
        // The manufacturer uses decimal numeric size labels (e.g. Stacka 5,5).
        // Accept these ONLY in an explicit horizontal 'Rozmiar' row, never in
        // measurement rows. Preserve the original representation and column.
        if ($allowDecimalSize && preg_match('/^[0-9]{1,2}[,.][0-9]{1,2}$/uD', $value)) {
            return ['size' => $value, 'flag' => 'DECIMAL_SIZE_LABEL_REVIEW'];
        }
        if (preg_match('/^(XXS|XS|S|M|L|XL|XXL|XXXL)\s+(\d+(?:[,.]\d+)?)$/uD', $value, $m)) {
            return ['size' => $m[1], 'flag' => 'SIZE_AND_MEASUREMENT_IN_SAME_CELL'];
        }
        if (preg_match('/^(XXS|XS|S|M|L|XL)\s+DZIECIĘCY$/uD', $value, $m)) {
            return ['size' => $m[1], 'flag' => 'CHILD_SIZE_QUALIFIER'];
        }
        return null;
    };

    $results = [];
    $counts = ['products' => 0, 'technical_tables' => 0, 'products_with_candidates' => 0, 'needs_review' => 0, 'candidate_size_entries' => 0];

    foreach ($data['products'] as $product) {
        if (!is_array($product)) {
            throw new RuntimeException('Unexpected product structure.');
        }
        ++$counts['products'];
        $entries = [];
        $flags = [];
        $unknown = [];
        foreach (($product['technical_tables'] ?? []) as $tableIndex => $table) {
            ++$counts['technical_tables'];
            $rows = array_map(static fn ($row) => array_map($normalise, is_array($row) ? array_values($row) : []), $table['rows'] ?? []);
            $rows = array_values(array_filter($rows, static fn ($row) => count($row) >= 2));
            if (!$rows) {
                $flags[] = 'EMPTY_OR_MALFORMED_TABLE';
                continue;
            }
            $first = mb_strtolower($rows[0][0]);
            $headerSize = preg_match('/^rozmiar(?:y|\s+cm)?\s*\**$/u', $first) === 1;
            $horizontal = $headerSize && count($rows[0]) > 2;
            // A non-leading size row can occur below a product-type row.
            $nonLeadingSizeRow = null;
            if (!$headerSize) {
                foreach ($rows as $index => $candidateRow) {
                    if ($index === 0 || !preg_match('/^rozmiar\s*$/iu', $candidateRow[0] ?? '')) continue;
                    if (count($candidateRow) <= 2) continue;
                    $valid = true;
                    foreach (array_slice($candidateRow, 1) as $cell) {
                        if ($parseSize($cell) === null) { $valid = false; break; }
                    }
                    if ($valid) { $nonLeadingSizeRow = $index; break; }
                }
            }
            $specialGrid = false;
            // Two-dimensional grids: known manufacturer structures, not a generic SKU inference.
            $name = (string) ($product['name'] ?? '');
            $isCollarStark = $name === 'Collar Stark';
            $isNtBac = str_starts_with($name, 'Kołnierz NT-BAC');
            if ($isCollarStark && count($rows[0]) === 5 && $rows[0][0] === 'Rozmiary') {
                $specialGrid = true;
                foreach (array_slice($rows, 1) as $ri => $row) {
                    if (!preg_match('/^(S|M|L|XL)\s*\(([^)]+)\)$/u', $row[0] ?? '', $m) || count($row) !== 5) {
                        $flags[] = 'INVALID_GRID_ROW'; continue;
                    }
                    for ($column = 1; $column < 5; ++$column) {
                        if (!preg_match('/^(?:S|M|L|XL)[2-5]$/D', $row[$column])) {
                            $flags[] = 'INVALID_GRID_CODE'; continue;
                        }
                        $entries[] = ['size' => $row[$column], 'raw_size' => $row[$column], 'table' => $tableIndex,
                            'measurements' => [
                                ['label' => 'Neck circumference', 'value' => $m[2]],
                                ['label' => 'Collar height', 'value' => $rows[0][$column]],
                            ]];
                    }
                }
                $flags[] = 'TWO_DIMENSIONAL_SIZE_GRID';
            } elseif ($isNtBac && count($rows[0]) === 7 && $rows[0][0] === '*') {
                $specialGrid = true;
                foreach (array_slice($rows, 1) as $row) {
                    if (!preg_match('/^\*\*\s*(\d+(?:[,.]\d+)?)$/u', $row[0] ?? '', $m) || count($row) !== 7) {
                        $flags[] = 'INVALID_GRID_ROW'; continue;
                    }
                    for ($column = 1; $column < 7; ++$column) {
                        if (!preg_match('/^[A-D][1-6]$/D', $row[$column])) {
                            $flags[] = 'INVALID_GRID_CODE'; continue;
                        }
                        $entries[] = ['size' => $row[$column], 'raw_size' => $row[$column], 'table' => $tableIndex,
                            'measurements' => [
                                ['label' => 'Range from header (*)', 'value' => $rows[0][$column]],
                                ['label' => 'Dimension from row (**)', 'value' => $m[1]],
                            ]];
                    }
                }
                $flags[] = 'TWO_DIMENSIONAL_SIZE_GRID';
            }

            // A size-header row can also be vertical: Rozmiar | Obwód ... | Wysokość ...
            if ($horizontal) {
                foreach (array_slice($rows[0], 1) as $cell) {
                    if ($parseSize($cell, true) === null) {
                        $horizontal = false;
                        break;
                    }
                }
            }
            if ($specialGrid) {
                // Already handled, preserving the code at each matrix intersection.
            } elseif ($nonLeadingSizeRow !== null) {
                $sizeRow = $rows[$nonLeadingSizeRow];
                for ($column = 1; $column < count($sizeRow); ++$column) {
                    $raw = $sizeRow[$column];
                    $parsed = $parseSize($raw);
                    $measurements = [];
                    foreach ($rows as $rowIndex => $row) {
                        if ($rowIndex === $nonLeadingSizeRow) continue;
                        if (isset($row[0], $row[$column])) {
                            $measurements[] = ['label' => $row[0], 'value' => $row[$column]];
                        } else {
                            $flags[] = 'IRREGULAR_COLUMN_COUNT';
                        }
                    }
                    $entries[] = ['size' => $parsed['size'], 'raw_size' => $raw, 'table' => $tableIndex, 'measurements' => $measurements];
                }
                $flags[] = 'NON_LEADING_SIZE_ROW';
            } elseif ($horizontal) {
                for ($column = 1; $column < count($rows[0]); ++$column) {
                    $raw = $rows[0][$column];
                    $parsed = $parseSize($raw, true);
                    if ($parsed === null) {
                        $unknown[] = ['table' => $tableIndex, 'value' => $raw];
                        continue;
                    }
                    $measurements = [];
                    foreach (array_slice($rows, 1) as $row) {
                        if (isset($row[0], $row[$column]) && $row[0] !== '' && $row[$column] !== '') {
                            $measurements[] = ['label' => $row[0], 'value' => $row[$column]];
                        } else {
                            $flags[] = 'IRREGULAR_COLUMN_COUNT';
                        }
                    }
                    $entries[] = ['size' => $parsed['size'], 'raw_size' => $raw, 'table' => $tableIndex, 'measurements' => $measurements];
                    if ($parsed['flag']) $flags[] = $parsed['flag'];
                }
            } elseif ($headerSize) {
                // Vertical table with label in first column.
                $headings = array_slice($rows[0], 1);
                foreach (array_slice($rows, 1) as $row) {
                    $raw = $row[0];
                    $parsed = $parseSize($raw, true);
                    if ($parsed === null) {
                        $unknown[] = ['table' => $tableIndex, 'value' => $raw];
                        $flags[] = 'UNRECOGNISED_VERTICAL_ROW';
                        continue;
                    }
                    $measurements = [];
                    foreach ($headings as $index => $heading) {
                        if (isset($row[$index + 1])) {
                            $measurements[] = ['label' => $heading, 'value' => $row[$index + 1]];
                        }
                    }
                    $entries[] = ['size' => $parsed['size'], 'raw_size' => $raw, 'table' => $tableIndex, 'measurements' => $measurements];
                    if ($parsed['flag']) $flags[] = $parsed['flag'];
                }
                if (count($headings) && in_array(mb_strtolower($headings[0]), ['mężczyźni', 'kobiety'], true)) {
                    $flags[] = 'GENDERED_MEASUREMENT_COLUMNS';
                }
            } else {
                // Special case like KAFO: header is not 'Rozmiar' but cells start 'S 16' etc.
                $mixed = false;
                foreach ($rows as $rowIndex => $row) {
                    $parsed = $parseSize($row[0]);
                    if ($parsed === null || $parsed['flag'] !== 'SIZE_AND_MEASUREMENT_IN_SAME_CELL') continue;
                    $mixed = true;
                    $entries[] = [
                        'size' => $parsed['size'], 'raw_size' => $row[0], 'table' => $tableIndex,
                        'measurements' => [['label' => 'raw_first_column_with_dimension', 'value' => $row[0]], ['label' => $rows[0][1] ?? 'related measurement', 'value' => $row[1] ?? '']],
                    ];
                }
                $flags[] = $mixed ? 'MIXED_SIZE_MEASUREMENT_TABLE_REVIEW' : 'UNRECOGNISED_TABLE_LAYOUT';
            }
        }
        $allLabels = array_column($entries, 'size');
        if (count($allLabels) !== count(array_unique($allLabels))) {
            $flags[] = 'DUPLICATE_SIZE_LABEL_WITH_DISTINCT_EVIDENCE';
        }
        $sizes = array_values(array_unique($allLabels));
        if ($sizes) ++$counts['products_with_candidates'];
        $counts['candidate_size_entries'] += count($entries);
        if (!$sizes) $flags[] = 'NO_STRUCTURED_SIZE_CANDIDATES';
        $flags = array_values(array_unique($flags));
        if ($flags || $unknown) ++$counts['needs_review'];
        $results[] = [
            'external_id' => $product['external_id'] ?? null,
            'name' => $product['name'] ?? null,
            'source_url' => $product['source_url'] ?? null,
            'candidate_sizes' => $sizes,
            'size_evidence' => $entries,
            'unclassified_cells' => $unknown,
            'review_flags' => $flags,
            'commercially_verified' => false,
        ];
    }
        return ['source' => 'qmed', 'analysis_type' => 'read_only_size_evidence', 'counts' => $counts, 'products' => $results];
    }
}
