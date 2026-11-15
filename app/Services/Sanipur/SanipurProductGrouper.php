<?php

declare(strict_types=1);

namespace App\Services\Sanipur;

use Illuminate\Support\Str;

/**
 * sanipur.eu sells every size and colour as its own page ("Matex Prześcieradło jersey z gumą 90/100x190/200,
 * turkusowe", SKU TM0131_31A). This groups those pages into one shop product per product line, with each page
 * becoming a variant that keeps its own SKU and price.
 *
 * Pages are grouped by the name without its size and colour, so all sizes and colours of one sheet become one
 * product even where Matex puts the size in the SKU line (TM0131 = 90/100, TM0134 = 150/160), while a pillow cover
 * and a blanket cover that share a SKU line stay separate products.
 */
final class SanipurProductGrouper
{
    private const SIZE_PATTERN = '/\(?\b\d{1,3}(?:\/\d{1,3})?\s*x\s*\d{1,3}(?:\/\d{1,3})?(?:\s*x\s*\d{1,3}(?:\/\d{1,3})?)?(?:\s*cm)?\b\)?/iu';

    /**
     * @param  list<array<string, mixed>>  $pages  Output of SanipurProductScraper::parse().
     * @return list<array<string, mixed>>
     */
    public function group(array $pages): array
    {
        $groups = [];
        $seenSkus = [];

        foreach ($pages as $page) {
            if (! is_array($page) || ! isset($page['external_product_id'], $page['price_gross_amount'])) {
                continue;
            }

            $sku = $this->string($page['sku'] ?? null);

            if ($sku !== null) {
                if (isset($seenSkus[mb_strtoupper($sku)])) {
                    continue;
                }

                $seenSkus[mb_strtoupper($sku)] = true;
            }

            $parsed = $this->parseName((string) ($page['name'] ?? ''));
            $line = $sku !== null ? $this->skuLine($sku) : null;
            $code = $sku !== null ? $this->colourCode($sku) : null;
            $key = Str::slug($parsed['base']) ?: 'id-'.$page['external_product_id'];

            $groups[$key][] = $page + [
                '_size' => $parsed['size'],
                '_colour' => $parsed['colour'],
                '_base' => $parsed['base'],
                '_colour_code' => $code,
                '_colour_keys' => $code === null ? [] : [$line.'|'.$code, 'brand:'.mb_strtolower((string) ($page['brand'] ?? '')).'|'.$code],
            ];
        }

        $votes = [];

        foreach ($groups as $members) {
            foreach ($members as $member) {
                if ($member['_colour'] === null) {
                    continue;
                }

                foreach ($member['_colour_keys'] as $colourKey) {
                    $name = mb_strtolower($member['_colour']);
                    $votes[$colourKey][$name] = ($votes[$colourKey][$name] ?? 0) + 1;
                }
            }
        }

        $colourNames = [];

        foreach ($votes as $colourKey => $names) {
            arsort($names);
            $colourNames[$colourKey] = (string) array_key_first($names);
        }

        $result = [];

        foreach ($groups as $key => $members) {
            $result[] = $this->buildGroup((string) $key, $this->fillMissingColours($members, $colourNames));
        }

        return $result;
    }

    /**
     * Many pages leave the colour out of the name ("Jersey 80x200") while a sibling in another size names it
     * ("Jersey 120x200, beżowe"). The SKU suffix carries the same colour code across sizes and product lines, so
     * the colour is taken from a sibling in the same SKU line, else the name most pages of that brand use for the
     * code, else shown as the code.
     *
     * @param  list<array<string, mixed>>  $members
     * @param  array<string, string>  $colourNames
     * @return list<array<string, mixed>>
     */
    private function fillMissingColours(array $members, array $colourNames): array
    {
        $hasColours = array_filter($members, fn (array $m): bool => $m['_colour'] !== null) !== [];
        $codes = array_unique(array_filter(array_column($members, '_colour_code')));

        if (count($members) < 2 || (! $hasColours && count($codes) < 2)) {
            return $members;
        }

        foreach ($members as $i => $member) {
            if ($member['_colour'] !== null || $member['_colour_code'] === null) {
                continue;
            }

            $name = null;

            foreach ($member['_colour_keys'] as $colourKey) {
                $name ??= $colourNames[$colourKey] ?? null;
            }

            $members[$i]['_colour'] = $name ?? 'nr '.$member['_colour_code'];
        }

        return $members;
    }

    /**
     * The colour part of a Sanipur/Matex SKU: the last two digits plus any letter ("TS0106_0609" → "09",
     * "TM0131_31A" → "31A"), or null when the SKU has no suffix.
     */
    private function colourCode(string $sku): ?string
    {
        if (preg_match('/_([\w+]+)$/u', $sku, $m) !== 1) {
            return null;
        }

        $suffix = mb_strtoupper($m[1]);

        if (str_contains($suffix, '+')) {
            return $suffix;
        }

        return preg_match('/(\d{2}[A-Z]?)$/u', $suffix, $code) === 1 ? $code[1] : null;
    }

    /**
     * @return array{base: string, size: string|null, colour: string|null}
     */
    public function parseName(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $size = null;
        $colour = null;
        $protected = [];
        $name = preg_replace_callback('/\([^()]*(?:,|-\d)[^()]*\)/u', function (array $m) use (&$protected): string {
            $protected[] = $m[0];

            return '@@'.(count($protected) - 1).'@@';
        }, $name) ?? $name;

        if (preg_match(self::SIZE_PATTERN, $name, $match) === 1) {
            $size = $this->normaliseSize($match[0]);
        }

        $segments = array_map('trim', explode(',', $name));

        if (count($segments) > 1) {
            $last = end($segments);

            if ($this->looksLikeColour($last)) {
                $colour = $last;
                array_pop($segments);
            }
        }

        $base = implode(', ', $segments);
        $base = preg_replace(self::SIZE_PATTERN, '', $base) ?? $base;
        $base = preg_replace('/\s*,\s*(?=,|$)/u', '', $base) ?? $base;
        $base = preg_replace('/\s+([,)])/u', '$1', $base) ?? $base;
        $base = trim(preg_replace('/\s{2,}/u', ' ', $base) ?? $base, ' ,-');

        $restore = fn (string $value): string => preg_replace_callback('/@@(\d+)@@/', fn (array $m) => $protected[(int) $m[1]], $value) ?? $value;
        $base = $base !== '' ? $base : $name;

        return ['base' => $restore($base), 'size' => $size, 'colour' => $colour === null ? null : $restore($colour)];
    }

    /**
     * The last comma segment is a colour or pattern ("jasno szare", "Dżungla", "wzór 2"), not a collection or
     * model name ("Kolekcja GOLD", "TULLY") or a measurement.
     */
    private function looksLikeColour(string $segment): bool
    {
        if (preg_match('/^wz[oó]r\s+\d+$/iu', $segment) === 1) {
            return true;
        }

        return $segment !== ''
            && preg_match('/\d/u', $segment) !== 1
            && preg_match('/^kolekcja/iu', $segment) !== 1
            && preg_match('/\p{Lu}{3,}/u', $segment) !== 1;
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private function buildGroup(string $key, array $members): array
    {
        usort($members, fn (array $a, array $b): int => [$this->sizeSortKey($a['_size']), mb_strtolower((string) $a['_colour']), $a['price_gross_amount']]
            <=> [$this->sizeSortKey($b['_size']), mb_strtolower((string) $b['_colour']), $b['price_gross_amount']]);

        $lead = $members[0];
        $multiple = count($members) > 1;
        $sizes = array_unique(array_map(fn (array $m) => (string) $m['_size'], $members));
        $colours = array_unique(array_map(fn (array $m) => mb_strtolower((string) $m['_colour']), $members));
        $useSize = $multiple && count($sizes) > 1;
        $useColour = $multiple && count($colours) > 1;

        $variants = [];
        $combinations = [];

        foreach ($members as $member) {
            $attributes = [];

            if ($useSize && $member['_size'] !== null) {
                $attributes[] = ['label' => 'Rozmiar', 'value' => $member['_size']];
            }

            if ($useColour && $member['_colour'] !== null) {
                $attributes[] = ['label' => 'Kolor', 'value' => Str::ucfirst($member['_colour'])];
            }

            $combination = mb_strtolower(implode('|', array_column($attributes, 'value')));

            if ($multiple && ($attributes === [] || isset($combinations[$combination]))) {
                $colourIndex = array_search('Kolor', array_column($attributes, 'label'), true);

                if ($colourIndex !== false && $member['_colour_code'] !== null) {
                    $attributes[$colourIndex]['value'] .= ' ('.$member['_colour_code'].')';
                } else {
                    $sku = (string) ($member['sku'] ?? $member['external_product_id']);
                    $attributes[] = ['label' => 'Wariant', 'value' => str_contains($sku, '_') ? substr($sku, strrpos($sku, '_') + 1) : $sku];
                }

                $combination = mb_strtolower(implode('|', array_column($attributes, 'value')));
            }

            $combinations[$combination] = true;

            $variants[] = [
                'external_variant_id' => (string) $member['external_product_id'],
                'source_sku' => $member['sku'] ?? null,
                'source_url' => $member['canonical_url'] ?? $member['source_url'] ?? null,
                'label' => $attributes === [] ? null : implode(' / ', array_column($attributes, 'value')),
                'price_gross_amount' => $member['price_gross_amount'],
                'old_price_gross_amount' => $member['old_price_gross_amount'] ?? null,
                'is_medical_device' => (bool) ($member['is_medical_device'] ?? false),
                'attributes' => $attributes,
            ];
        }

        $name = $multiple ? $lead['_base'] : (string) ($lead['name'] ?? $lead['_base']);

        return [
            'source' => 'sanipur',
            'external_product_id' => $this->externalId($key),
            'group_key' => $key,
            'name' => $name,
            'slug' => Str::slug($name),
            'brand' => $lead['brand'] ?? null,
            'breadcrumbs' => $this->breadcrumbs($members),
            'short_description' => $lead['short_description'] ?? null,
            'description_html' => $lead['description_html'] ?? null,
            'source_url' => $lead['canonical_url'] ?? $lead['source_url'] ?? null,
            'source_urls' => array_values(array_filter(array_map(fn (array $m) => $m['canonical_url'] ?? $m['source_url'] ?? null, $members))),
            'is_medical_device' => (bool) array_filter(array_map(fn (array $m) => (bool) ($m['is_medical_device'] ?? false), $members)),
            'attributes' => $this->productAttributes($lead['attributes'] ?? [], $multiple),
            'images' => $this->images($members),
            'variant_candidates' => $variants,
        ];
    }

    /**
     * The lead page's specification rows, minus the ones that differ per variant (size, colour) when the
     * product has several variants.
     *
     * @return list<array{label: string, value: string}>
     */
    private function productAttributes(mixed $attributes, bool $multiple): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        return array_values(array_filter($attributes, fn ($row): bool => is_array($row)
            && isset($row['label'], $row['value'])
            && (! $multiple || preg_match('/^(rozmiar|wymiar[y]?|kolor|wz[oó]r)$/iu', trim((string) $row['label'])) !== 1)));
    }

    private function externalId(string $key): string
    {
        $id = Str::slug($key);

        return mb_strlen($id) <= 120 ? $id : mb_substr($id, 0, 109).'-'.substr(sha1($id), 0, 10);
    }

    /**
     * The lead page's gallery first, then the main photo of each other variant (one per colour).
     *
     * @param  list<array<string, mixed>>  $members
     * @return list<array{url: string, alt: string}>
     */
    private function images(array $members): array
    {
        $images = [];

        foreach (($members[0]['images'] ?? []) as $image) {
            if (is_array($image) && isset($image['url'])) {
                $images[$image['url']] = $image;
            }
        }

        foreach (array_slice($members, 1) as $member) {
            $first = $member['images'][0] ?? null;

            if (is_array($first) && isset($first['url']) && ! isset($images[$first['url']])) {
                $images[$first['url']] = $first;
            }
        }

        return array_values($images);
    }

    /**
     * The category most of the product's pages sit in (the smallest sheet size is often filed under children's
     * sheets only).
     *
     * @param  list<array<string, mixed>>  $members
     * @return list<string>
     */
    private function breadcrumbs(array $members): array
    {
        $paths = [];

        foreach ($members as $member) {
            if (is_array($member['breadcrumbs'] ?? null) && $member['breadcrumbs'] !== []) {
                $path = array_values($member['breadcrumbs']);
                $key = implode(' > ', SanipurProductImporter::categoryPath($path));
                $paths[$key] ??= ['path' => $path, 'count' => 0];
                $paths[$key]['count']++;
            }
        }

        if ($paths === []) {
            return [];
        }

        uasort($paths, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return reset($paths)['path'];
    }

    private function skuLine(string $sku): string
    {
        $line = preg_split('/[_(]/', $sku)[0] ?? $sku;

        return trim($line) !== '' ? trim($line) : $sku;
    }

    private function normaliseSize(string $size): string
    {
        $size = trim($size, '() ');
        $size = preg_replace('/\s*x\s*/iu', 'x', $size) ?? $size;
        $size = preg_replace('/\s*cm$/iu', '', $size) ?? $size;

        return $size.' cm';
    }

    /**
     * @return list<int>
     */
    private function sizeSortKey(?string $size): array
    {
        if ($size === null) {
            return [0];
        }

        preg_match_all('/\d+/', $size, $m);

        return array_map('intval', $m[0]);
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
