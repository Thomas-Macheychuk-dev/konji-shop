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

    /**
     * Symbols keyed by their letters and digits only, for SKUs written with other separators ("ATTACK-OA" for "ATTACK OA").
     * A key shared by two symbols is null so it never matches.
     *
     * @var array<string, ?string>
     */
    private array $symbolsByLooseKey = [];

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

        foreach (array_keys($list->rowsBySymbol) as $symbol) {
            $key = self::looseKey((string) $symbol);
            $list->symbolsByLooseKey[$key] = array_key_exists($key, $list->symbolsByLooseKey) ? null : (string) $symbol;
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

    /**
     * The list symbol for a code, matched exactly or ignoring separators.
     */
    public function resolve(string $code): ?string
    {
        $code = self::normalize($code);

        if (isset($this->rowsBySymbol[$code])) {
            return $code;
        }

        return $this->symbolsByLooseKey[self::looseKey($code)] ?? null;
    }

    /**
     * Longest list symbol that appears as a whole word in the text, e.g. the code in "Orteza nadgarstka AM-OSN-U-02".
     * Only symbols with a digit, hyphen or slash count, so model names like SPARTAN or common words never match prose,
     * unless the whole text is the symbol ("Kombiwar").
     */
    public function symbolIn(string $text): ?string
    {
        if (isset($this->rowsBySymbol[self::normalize($text)])) {
            return self::normalize($text);
        }

        // Spaces around slashes are kept here: "Orteza ... / ATTACK 2RA OA" must still have a word boundary.
        $text = ' '.(string) preg_replace('/\s+/u', ' ', mb_strtoupper($text)).' ';
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

        return $this->pick($rows, $forChild);
    }

    /**
     * One price from candidate rows, preferring the adult or children's rows when they differ.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{status: 'match'|'ambiguous'|'missing', net_minor?: int, row?: array<string, mixed>, candidates?: list<array<string, mixed>>}
     */
    private function pick(array $rows, bool $forChild): array
    {
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

    /**
     * Whether the product name describes an accessory for the coded product ("Pokrowiec do AM-ADS-R").
     */
    public static function isAccessoryName(string $name, string $code): bool
    {
        return (bool) preg_match(
            '/\bDO\s+(?:ORTEZY\s+|APARATU\s+)?'.preg_quote(self::normalize($code), '/').'(?=$|[\s),.])/u',
            self::normalize($name),
        );
    }

    /**
     * Price of an accessory for the coded product: a row listed under that symbol or naming it in its description,
     * whose description starts like the product name ("Pokrowiec ..." -> "POKROWIEC NIEPRZEMAKALNY ...").
     *
     * @return array{status: 'match'|'ambiguous'|'missing', net_minor?: int, row?: array<string, mixed>, candidates?: list<array<string, mixed>>}
     */
    public function accessoryPrice(string $code, string $name, bool $forChild): array
    {
        $code = self::normalize($code);
        $firstWord = strtok(self::normalize($name), ' ');
        $rows = [];

        foreach ($this->rowsBySymbol as $symbol => $symbolRows) {
            foreach ($symbolRows as $row) {
                $description = self::normalize((string) $row['description']);
                $forCode = ($row['accessory'] && str_starts_with((string) $symbol, $code))
                    || preg_match('/(?<=^|\s)'.preg_quote($code, '/').'(?=$|[\s),.])/u', $description);

                if ($forCode && $firstWord !== false && str_starts_with($description, $firstWord)) {
                    $rows[] = $row;
                }
            }
        }

        return $this->pick($rows, $forChild);
    }

    public static function isChildText(string $text): bool
    {
        return (bool) preg_match(self::CHILD_PATTERN, mb_strtoupper($text));
    }

    private static function looseKey(string $code): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper($code));
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
