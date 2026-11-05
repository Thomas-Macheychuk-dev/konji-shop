<?php

declare(strict_types=1);

namespace App\Services\Reh4Mat;

use App\Enums\ProductVariantStatus;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Sizes and sides a Reh4Mat product is sold in, read from the "Rozmiar" table and the
 * "Występuje jako prawy / lewy" note in its imported description.
 *
 * Reh4Mat publishes no structured variants, so this is the only evidence. Anything the parser
 * cannot read with confidence becomes STATUS_REVIEW, and those products are never split.
 */
final class Reh4MatSizeOptions
{
    /** Several sizes and/or a left and right version: the product needs option variants. */
    public const STATUS_SPLIT = 'split';

    /** A size table the parser could not read with confidence. */
    public const STATUS_REVIEW = 'review';

    /** One universal or single size and no sides: the default variant is enough. */
    public const STATUS_SINGLE = 'single';

    public const STATUS_NO_TABLE = 'no_table';

    private const SIZE = '(?:X{0,4}S|M|X{0,4}L|[2-5]XL|\d{1,2}(?:[,.]5)?)';

    private const SIZE_RANKS = [
        'XXXXS' => 10, 'XXXS' => 20, 'XXS' => 30, 'XS' => 40, 'S' => 50, 'M' => 60, 'L' => 70,
        'XL' => 80, 'XXL' => 90, '2XL' => 90, 'XXXL' => 100, '3XL' => 100, 'XXXXL' => 110, '4XL' => 110, '5XL' => 120,
    ];

    /**
     * @param  list<string>  $sizes
     * @param  list<string>  $flags
     * @param  list<string>  $unreadLabels
     */
    private function __construct(
        public readonly string $status,
        public readonly array $sizes,
        public readonly bool $sided,
        public readonly array $flags,
        public readonly array $unreadLabels,
    ) {}

    public static function fromDescription(?string $html): self
    {
        $html = (string) $html;
        $sizes = [];
        $flags = [];
        $unread = [];
        $universal = false;
        $tables = 0;

        foreach (self::sizeTables($html) as $rows) {
            $tables++;

            foreach (self::labels($rows) as $label) {
                $normalized = self::normalizeLabel($label);

                // Repeated headers and the "przód | tył" (front | back) sub-header over measurement columns.
                if (in_array($normalized, ['', 'ROZMIAR', 'PRZÓD', 'TYŁ'], true)) {
                    continue;
                }

                if (preg_match('/^(X-?SIZE|UNIW|UNIVERS|ROZMIAR UNIWERSALNY)/u', $normalized) === 1) {
                    $universal = true;

                    continue;
                }

                if (preg_match('/^'.self::SIZE.'(?:\/(?:'.self::SIZE.'|SLIM|WIDE))?$/u', $normalized) === 1) {
                    $sizes[$normalized] = true;

                    continue;
                }

                $unread[] = $label;
            }
        }

        // PHP turns numeric keys such as shoe size "36" into ints.
        $sizes = array_map(strval(...), array_keys($sizes));
        $sided = preg_match('/występuje\s+jako\s+(?:prawy\s*\/\s*lewy|lewy\s*\/\s*prawy)/iu', self::text($html)) === 1;

        if ($unread !== []) {
            $flags[] = 'UNREAD_SIZE_LABEL';
        }

        if ($universal && $sizes !== []) {
            $flags[] = 'UNIVERSAL_AND_SIZES';
        }

        $status = match (true) {
            $flags !== [] => self::STATUS_REVIEW,
            count($sizes) > 1 || $sided => self::STATUS_SPLIT,
            $tables > 0 => self::STATUS_SINGLE,
            default => self::STATUS_NO_TABLE,
        };

        // A single size is still shown when the product is split by side, so the cart says which size it is.
        return new self($status, $status === self::STATUS_SPLIT && count($sizes) > 1 ? $sizes : [], $sided, $flags, $unread);
    }

    public function needsVariants(): bool
    {
        return $this->status === self::STATUS_SPLIT;
    }

    /**
     * Whether the description shows a choice the shopper must make (sizes or sides), readable or not.
     */
    public function offersChoice(): bool
    {
        return $this->status === self::STATUS_SPLIT || $this->status === self::STATUS_REVIEW;
    }

    /**
     * True for a Reh4Mat product whose description offers sizes or sides but that has no active
     * size/side variants yet, so a shopper could only order it without saying which one.
     */
    public static function missingChoice(Product $product): bool
    {
        if ($product->external_source !== 'reh4mat') {
            return false;
        }

        $hasOptionVariant = $product->variants->contains(
            static fn (ProductVariant $variant): bool => $variant->status === ProductVariantStatus::ACTIVE
                && Reh4MatSizeVariantSync::isOptionVariant($variant),
        );

        return ! $hasOptionVariant && self::fromDescription($product->description)->offersChoice();
    }

    /**
     * Position of a size label among the others, so S comes before M and "S/SLIM" before "S/WIDE".
     */
    public static function sortOrder(string $size): int
    {
        $parts = explode('/', $size, 2);
        $rank = self::rank($parts[0]);

        if (! isset($parts[1])) {
            return $rank * 100;
        }

        return $rank * 100 + match ($parts[1]) {
            'SLIM' => 1,
            'WIDE' => 2,
            default => min(99, intdiv(self::rank($parts[1]), 2)),
        };
    }

    private static function rank(string $size): int
    {
        if (isset(self::SIZE_RANKS[$size])) {
            return self::SIZE_RANKS[$size];
        }

        // Numeric sizes (shoes, "5") sort by value after the letter sizes.
        return 200 + (int) round((float) str_replace(',', '.', $size) * 2);
    }

    private static function normalizeLabel(string $label): string
    {
        $label = mb_strtoupper(trim(rtrim(trim($label), '*')));

        return (string) preg_replace('/\s*\/\s*/u', '/', $label);
    }

    /**
     * Tables whose first cell starts with "Rozmiar" ("Rozmiar", "Rozmiar ortezy", "Rozmiar*").
     *
     * @return list<list<list<string>>>
     */
    private static function sizeTables(string $html): array
    {
        $tables = [];

        preg_match_all('/<table\b.*?<\/table>/isu', $html, $matches);

        foreach ($matches[0] as $table) {
            preg_match_all('/<tr\b.*?<\/tr>/isu', $table, $rowMatches);

            $rows = [];

            foreach ($rowMatches[0] as $row) {
                preg_match_all('/<t[hd]\b[^>]*>(.*?)<\/t[hd]>/isu', $row, $cellMatches);
                $cells = array_map(self::text(...), $cellMatches[1]);

                if (array_filter($cells, static fn (string $cell): bool => $cell !== '') !== []) {
                    $rows[] = $cells;
                }
            }

            if ($rows !== [] && preg_match('/^rozmiar/iu', $rows[0][0] ?? '') === 1) {
                $tables[] = $rows;
            }
        }

        return $tables;
    }

    /**
     * Size labels of a table: the first row when the sizes run across it, else the first column.
     *
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private static function labels(array $rows): array
    {
        $header = array_slice($rows[0], 1);
        $acrossFirstRow = count($header) > 1 && array_filter(
            $header,
            static fn (string $cell): bool => preg_match('/^'.self::SIZE.'$/u', self::normalizeLabel($cell)) !== 1,
        ) === [];

        if ($acrossFirstRow) {
            return $header;
        }

        return array_map(static fn (array $row): string => $row[0] ?? '', array_slice($rows, 1));
    }

    private static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
