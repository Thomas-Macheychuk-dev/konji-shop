<?php

declare(strict_types=1);

namespace App\Services\Corradomed;

use App\Enums\VatRate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Corradomed's wholesale price list (resources/import-data/corradomed, built from the supplier PDF by
 * scripts/catalogue/corradomed-2026/build_price_list.py): net prices per code in eight finish columns.
 *
 * Shop price = list net + MARKUP, plus the list VAT, rounded to whole złoty (the owner's rule for
 * equipment). Krzysztof asked for list + 25% on 16 November 2026.
 */
final class CorradomedPriceList
{
    public const DEFAULT_PATH = 'resources/import-data/corradomed/price-list-2026-03-01.json';

    public const MARKUP_PERCENT = 25;

    public const COLUMNS = [
        'painted_25_white', 'painted_25_black', 'inox_25', 'inox_32',
        'masked_32_white', 'masked_32_black', 'painted_32_white', 'painted_32_black',
    ];

    /**
     * @var array<string, array{code: string, name: string, vat_rate: int, made_to_order: bool, prices: array<string, int|null>}>
     */
    private array $items = [];

    /**
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * @var array<string, list<string>>
     */
    private array $pageCodes = [];

    /**
     * @var array<string, string>
     */
    private array $pageNames = [];

    private string $validFrom = '';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(array $data)
    {
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (! is_array($item) || ! is_string($item['code'] ?? null)) {
                continue;
            }

            $prices = [];

            foreach (self::COLUMNS as $column) {
                $value = $item['prices'][$column] ?? null;
                $prices[$column] = is_int($value) && $value > 0 ? $value : null;
            }

            $this->items[self::normaliseCode($item['code'])] = [
                'code' => $item['code'],
                'name' => (string) ($item['name'] ?? ''),
                'vat_rate' => (int) ($item['vat_rate'] ?? 0),
                'made_to_order' => (bool) ($item['made_to_order'] ?? false),
                'prices' => $prices,
            ];
        }

        foreach ((array) ($data['aliases'] ?? []) as $from => $to) {
            $this->aliases[self::normaliseCode((string) $from)] = self::normaliseCode((string) $to);
        }

        foreach ((array) ($data['page_codes'] ?? []) as $page => $codes) {
            $this->pageCodes[(string) $page] = array_values(array_map('strval', (array) $codes));
        }

        foreach ((array) ($data['page_names'] ?? []) as $page => $name) {
            $this->pageNames[(string) $page] = (string) $name;
        }

        $this->validFrom = (string) ($data['valid_from'] ?? '');

        if ($this->items === []) {
            throw new InvalidArgumentException('The Corradomed price list has no items.');
        }
    }

    public static function fromFile(?string $path = null): self
    {
        $path ??= base_path(self::DEFAULT_PATH);

        if (! is_file($path)) {
            throw new RuntimeException('Corradomed price list not found: '.$path);
        }

        return new self(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Comparable form of a code from the site or the list: finish markers (INOX, M, ø 32) and the left/right
     * marker of the bath bars dropped, spacing and dots removed, "UKS3" written as "UKS-3".
     */
    public static function normaliseCode(string $code): string
    {
        $code = Str::upper(Str::ascii(trim($code)));
        $code = preg_replace('/Ø\s*\d+|\bO\s*\d{2}\b|\*/u', ' ', $code) ?? $code;
        $code = preg_replace('/\b(INOX|M)\b/', ' ', $code) ?? $code;
        $code = preg_replace('/[\s.]+/', '', $code) ?? $code;
        $code = preg_replace('/^(UPPP|UWPP|UWP-P)-[LP]-/', '$1-', $code) ?? $code;

        return preg_replace('/^([A-Z]+)(\d)/', '$1-$2', $code) ?? $code;
    }

    public function validFrom(): string
    {
        return $this->validFrom;
    }

    /**
     * List codes a page sells instead of its table codes, when the list maps the page explicitly.
     *
     * @return list<string>|null
     */
    public function pageCodes(string $page): ?array
    {
        return $this->pageCodes[$page] ?? null;
    }

    public function pageName(string $page): ?string
    {
        return $this->pageNames[$page] ?? null;
    }

    /**
     * @return array{code: string, name: string, vat_rate: int, made_to_order: bool, prices: array<string, int|null>}|null
     */
    public function find(string $code): ?array
    {
        $key = self::normaliseCode($code);

        return $this->items[$this->aliases[$key] ?? $key] ?? $this->items[$key] ?? null;
    }

    /**
     * Shop gross price in grosze for a list net price (whole złoty).
     */
    public static function grossMinorUnits(int $netZloty, VatRate $vatRate): int
    {
        $gross = $netZloty * (100 + self::MARKUP_PERCENT) / 100 * $vatRate->multiplier();

        return (int) round($gross, 0, PHP_ROUND_HALF_UP) * 100;
    }

    /**
     * List rows no page used, for the import report.
     *
     * @param  list<string>  $usedCodes  list codes as returned in find()['code']
     * @return list<string>
     */
    public function unusedCodes(array $usedCodes): array
    {
        $used = array_flip($usedCodes);

        return array_values(array_filter(
            array_map(fn (array $item): string => $item['code'], array_values($this->items)),
            fn (string $code): bool => ! isset($used[$code]),
        ));
    }
}
