<?php

declare(strict_types=1);

namespace App\Services\Reh4Mat;

use RuntimeException;

/**
 * Net prices from the Reh4Mat price list, keyed by catalogue symbol.
 *
 * The reference JSON is generated from the supplier PDF by
 * scripts/catalogue/reh4mat-price-list-20260601/parse.py. One symbol can have several rows: an
 * adult and a children's version ("AM-OSS-03" 115 / "DZIECIĘCA ..." 130), the same item repeated
 * in several sections, or accessories sold under the parent symbol.
 */
final class Reh4MatPriceList
{
    public const DEFAULT_PATH = 'import-data/reh4mat/price-list-2026-06-01.json';

    private const CHILD_PATTERN = '/\b(DZIECI|DZIĘCI|PEDIATR)/u';

    /** @var array<string, list<array<string, mixed>>> */
    private array $rowsBySymbol = [];

    /** @var array<string, mixed> */
    private array $meta = [];

    public static function load(?string $path = null): self
    {
        $path ??= resource_path(self::DEFAULT_PATH);
        $raw = is_file($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            throw new RuntimeException('Reh4Mat price list cannot be read: '.$path);
        }

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded['rows'] ?? null) || ($decoded['currency'] ?? null) !== 'PLN' || ($decoded['price_basis'] ?? null) !== 'net') {
            throw new RuntimeException('Reh4Mat price list must hold net PLN rows: '.$path);
        }

        $list = new self;
        $list->meta = array_diff_key($decoded, ['rows' => true]);

        foreach ($decoded['rows'] as $row) {
            if (! is_array($row) || ! is_int($row['net_minor'] ?? null) || $row['net_minor'] <= 0) {
                throw new RuntimeException('Reh4Mat price list has a row without a positive net price.');
            }

            $list->rowsBySymbol[self::normalize((string) $row['symbol'])][] = $row;
        }

        return $list;
    }

    public static function normalize(string $code): string
    {
        $code = mb_strtoupper(trim($code));
        $code = str_replace(['–', '—'], '-', $code);
        $code = (string) preg_replace('/\s*([\/,])\s*/u', '$1', $code);

        return (string) preg_replace('/\s+/u', ' ', $code);
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function has(string $code): bool
    {
        return isset($this->rowsBySymbol[self::normalize($code)]);
    }

    /**
     * Longest list symbol that appears as a whole word in the text, e.g. the code in "Orteza nadgarstka AM-OSN-U-02".
     * Only symbols with a digit, hyphen or slash count, so model names like SPARTAN or common words never match prose.
     */
    public function symbolIn(string $text): ?string
    {
        $text = ' '.self::normalize($text).' ';
        $found = null;

        foreach (array_keys($this->rowsBySymbol) as $symbol) {
            $symbol = (string) $symbol;

            if (! preg_match('/[\d\/-]/', $symbol) || ($found !== null && mb_strlen($found) >= mb_strlen($symbol))) {
                continue;
            }

            if (preg_match('/(?<=[\s(])'.preg_quote($symbol, '/').'(?=[\s),.])/u', $text)) {
                $found = $symbol;
            }
        }

        return $found;
    }

    /**
     * Resolve one price for a symbol.
     *
     * @return array{status: 'match'|'ambiguous'|'missing', net_minor?: int, row?: array<string, mixed>, candidates?: list<array<string, mixed>>}
     */
    public function price(string $code, bool $forChild): array
    {
        $code = self::normalize($code);
        $rows = $this->rowsBySymbol[$code] ?? [];

        if (! str_contains($code, 'AKCESORIA')) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => ! $row['accessory']));
        }

        if ($rows === []) {
            return ['status' => 'missing'];
        }

        if (count(self::distinctPrices($rows)) > 1) {
            $sameAge = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (bool) preg_match(self::CHILD_PATTERN, mb_strtoupper((string) $row['description'])) === $forChild,
            ));

            if ($sameAge !== [] && count(self::distinctPrices($sameAge)) === 1) {
                $rows = $sameAge;
            }
        }

        if (count(self::distinctPrices($rows)) > 1) {
            return ['status' => 'ambiguous', 'candidates' => $rows];
        }

        return ['status' => 'match', 'net_minor' => $rows[0]['net_minor'], 'row' => $rows[0]];
    }

    public static function isChildText(string $text): bool
    {
        return (bool) preg_match(self::CHILD_PATTERN, mb_strtoupper($text));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private static function distinctPrices(array $rows): array
    {
        return array_values(array_unique(array_map(static fn (array $row): int => $row['net_minor'], $rows)));
    }
}
