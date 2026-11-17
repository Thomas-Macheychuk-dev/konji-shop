<?php

declare(strict_types=1);

namespace App\Services\Products;

/**
 * Builds clothing product names as "{producer} {model} {garment}" (owner's feedback, 16 November 2026):
 * "elegancka sukienka medyczna długi rękaw LENA" becomes "Eldan Lena Sukienka" and
 * "Sukienka medyczna biała ze stójką SU 16, krótki rękaw, Premium" becomes "Apolonia SU 16 Sukienka Biała".
 *
 * Suppliers sell each colour, sleeve length or fabric of a model as a separate product, so products that
 * would share a name get the fewest details that tell them apart, in this order: colour or print, sleeve,
 * fabric line, men's/women's, cut ("Eldan Lena Flex Sukienka Krótki Rękaw"). Products whose model or
 * garment cannot be read from the name, or that no detail tells apart, are reported and left alone.
 */
final class ClothingProductNames
{
    public const SKIP_NO_GARMENT = 'NO_GARMENT';

    public const SKIP_NO_MODEL = 'NO_MODEL';

    public const SKIP_ALREADY_RENAMED = 'ALREADY_RENAMED';

    public const SKIP_SAME_NAME = 'SAME_NAME_AS_ANOTHER_PRODUCT';

    /** Details that tell products of one model apart, in the order they are added. */
    private const DETAILS = ['colour', 'sleeve', 'fabric', 'gender', 'cut'];

    /**
     * Garment patterns in order of precedence (a fleece "bluza polarowa" before a plain "bluza"). Each is
     * matched as whole words; PCRE's \b does not treat Polish letters such as "ż" as word characters.
     */
    private const GARMENTS = [
        'bluza .*polarow\p{L}*' => 'Bluza Polarowa',
        'kamizelka polarow\p{L}*' => 'Kamizelka Polarowa',
        'bezrękawnik polarow\p{L}*' => 'Bezrękawnik Polarowy',
        'bezrękawnik' => 'Bezrękawnik',
        'polar' => 'Polar',
        'koszulka polo' => 'Koszulka Polo',
        'sukienka' => 'Sukienka',
        'bluzka' => 'Bluzka',
        'bluza' => 'Bluza',
        'spódnica' => 'Spódnica',
        'spodnie' => 'Spodnie',
        'żakiet' => 'Żakiet',
        'fartuch' => 'Fartuch',
        'marynarka' => 'Marynarka',
        'tunika' => 'Tunika',
        'koszulka' => 'Koszulka',
        'kamizelka' => 'Kamizelka',
    ];

    /** Two-word colours, checked before single words ("beż nude" rather than nothing). */
    private const COLOUR_PHRASES = [
        'classic blue', 'beż nude', 'beż piaskowy', 'ciepły szary', 'butelkowa zieleń', 'turkus ciemny',
        'jasnoszary melanż', 'ciemnoszary melanż',
    ];

    private const COLOUR_WORD = '/(?<!\p{L})((?:jasno|ciemno)?(?:biał|granatow|niebiesk|błękitn|popielat|szar|czarn|czerwon|zielon|morsk|grafitow|chabrow|turkusow|fioletow|lawendow|koralow|czekoladow|oliwkow|rubinow|pomarańczow|morelow|amarantow|beżow|malinow|jagodow|siwoszar|fuksjow|różow|żółt)\p{L}*|mokka|bordo|fuksja)(?!\p{L})/u';

    /** Apolonia fabric lines, longest first. */
    private const FABRICS = ['Premium Stretch Cotton', 'Premium Stretch', 'Premium', 'Elegant Stretch', 'Comfort Stretch'];

    private const CUTS = [
        'joggery', 'petite', 'z szerokimi nogawkami', 'chirurgiczn', 'wiązan', 'ze stójką', 'z kołnierzem',
        'z dekoltem v', 'z uniwersalnym dekoltem', 'zapinany na bok', 'z plisą', 'rehabilitacyjn', 'ołówkow',
        'dopasowan', 'luźn', 'oversize', 'taliowan', 'rozpinan',
    ];

    /** Upper-case words in Eldan names that are not part of the model. */
    private const NOT_MODEL_WORDS = ['WYPRZEDAŻ'];

    /** Acronyms kept in capitals inside a model ("Prime GOTS 234"). */
    private const ACRONYMS = ['GOTS'];

    /**
     * @param  list<array{id: int, name: string, brand: string}>  $products  every product in scope, so names can be compared
     * @param  list<string>  $otherNames  names of products outside the rename that new names must not repeat
     * @return array{renames: list<array{id: int, old: string, new: string}>, skipped: list<array{id: int, name: string, reason: string}>}
     */
    public static function plan(array $products, array $otherNames = []): array
    {
        $skipped = [];
        $groups = [];

        foreach ($products as $product) {
            $original = $product['name'];
            // Some supplier names store "ó" or "ż" as a letter plus a combining accent.
            $name = trim((string) preg_replace('/\s+/u', ' ', \Normalizer::normalize($original) ?: $original));
            $brand = trim($product['brand']);

            if (mb_stripos($name, $brand.' ') === 0) {
                $skipped[] = ['id' => $product['id'], 'name' => $name, 'reason' => self::SKIP_ALREADY_RENAMED];
                $otherNames[] = $name;

                continue;
            }

            $garment = self::garment($name);

            if ($garment === null) {
                $skipped[] = ['id' => $product['id'], 'name' => $name, 'reason' => self::SKIP_NO_GARMENT];

                continue;
            }

            $model = self::model($name, $brand);

            if ($model === null) {
                $skipped[] = ['id' => $product['id'], 'name' => $name, 'reason' => self::SKIP_NO_MODEL];

                continue;
            }

            $base = $brand.' '.$model.' '.$garment;
            $groups[mb_strtolower($base)][] = [
                'id' => $product['id'],
                'old' => $original,
                'base' => $base,
                'details' => self::details($name),
                'suffix' => [],
            ];
        }

        $named = [];

        foreach ($groups as $group) {
            foreach (self::tellApart($group, self::DETAILS) as $item) {
                $named[] = $item;
            }
        }

        $taken = array_count_values(array_map(mb_strtolower(...), $otherNames));
        $newNames = array_count_values(array_map(fn (array $item): string => mb_strtolower(self::joined($item)), array_filter($named, fn (array $item): bool => $item['suffix'] !== false)));
        $renames = [];

        foreach ($named as $item) {
            $new = $item['suffix'] === false ? null : self::joined($item);

            if ($new === null || isset($taken[mb_strtolower($new)]) || $newNames[mb_strtolower($new)] > 1) {
                $skipped[] = ['id' => $item['id'], 'name' => $item['old'], 'reason' => self::SKIP_SAME_NAME];

                continue;
            }

            if ($new !== $item['old']) {
                $renames[] = ['id' => $item['id'], 'old' => $item['old'], 'new' => $new];
            }
        }

        usort($renames, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        usort($skipped, fn (array $a, array $b): int => [$a['reason'], $a['id']] <=> [$b['reason'], $b['id']]);

        return ['renames' => $renames, 'skipped' => $skipped];
    }

    public static function garment(string $name): ?string
    {
        $lower = mb_strtolower($name);

        foreach (self::GARMENTS as $pattern => $garment) {
            // "SpodnieS4034" (a Wojdak typo) still starts with "spodnie".
            $end = $garment === 'Spodnie' ? '' : '(?!\p{L})';

            if (preg_match('/(?<!\p{L})'.str_replace(' ', '\s+', $pattern).$end.'/u', $lower)) {
                return $garment;
            }
        }

        return null;
    }

    /**
     * The model as the producer names it: Apolonia's code ("SU 16", "BL 52.2"), Wojdak's code ("M8735"),
     * Eldan's capitalised model ("LENA FLEX" -> "Lena Flex"), otherwise the capitalised words after the
     * first word, without the producer ("Koszulka polo męska Malfini COTTON 212" -> "Cotton 212").
     */
    public static function model(string $name, string $brand): ?string
    {
        return match (mb_strtolower($brand)) {
            'apolonia' => preg_match('/(?<![\p{L}\d])([A-Z]{2})\s?(\d{1,3}(?:\.\d+)?)(\s+J\b)?/u', $name, $match)
                ? $match[1].' '.$match[2].($match[3] ?? '')
                : null,
            'wojdak' => preg_match('/(?<![\p{Lu}\d])([A-Z]\d{4})\b/u', $name, $match) ? $match[1] : null,
            'eldan' => self::eldanModel($name),
            default => self::capitalisedModel($name, $brand),
        };
    }

    /**
     * @return array<string, string|null>
     */
    public static function details(string $name): array
    {
        $lower = mb_strtolower($name);

        return [
            'colour' => self::colour($lower),
            'sleeve' => match (true) {
                (bool) preg_match('/(krótk\p{L}*|krót\.|kr\.)\s*r[ęe]k/u', $lower) => 'Krótki Rękaw',
                (bool) preg_match('/(dług\p{L}*|dł\.)\s*r[ęe]k/u', $lower) => 'Długi Rękaw',
                (bool) preg_match('/3\/4/u', $lower) && (bool) preg_match('/r[ęe]k/u', $lower) => 'Rękaw 3/4',
                default => null,
            },
            'fabric' => self::firstOf(self::FABRICS, $name),
            'gender' => match (true) {
                (bool) preg_match('/(?<!\p{L})(męsk\p{L}*|męs\.)/u', $lower, $match) => self::title(str_ends_with($match[1], '.') ? 'męskie' : $match[1]),
                (bool) preg_match('/(?<!\p{L})(damsk\p{L}*|dam\.)/u', $lower, $match) => self::title(str_ends_with($match[1], '.') ? 'damskie' : $match[1]),
                (bool) preg_match('/(?<!\p{L})unisex(?!\p{L})/u', $lower) => 'Unisex',
                default => null,
            },
            'cut' => self::cut($lower),
        ];
    }

    /**
     * Splits a group of products that would share a name by the first detail that differs, adding that
     * detail to each name, until every product has a name of its own. A product the details cannot tell
     * apart gets suffix false.
     *
     * @param  list<array<string, mixed>>  $group
     * @param  list<string>  $details
     * @return list<array<string, mixed>>
     */
    private static function tellApart(array $group, array $details): array
    {
        if (count($group) === 1) {
            return $group;
        }

        foreach ($details as $index => $detail) {
            $values = array_unique(array_map(fn (array $item): string => (string) $item['details'][$detail], $group));

            if (count($values) < 2) {
                continue;
            }

            $parts = [];

            foreach ($group as $item) {
                $value = $item['details'][$detail];

                if ($value !== null) {
                    $item['suffix'][] = $value;
                }

                $parts[(string) $value][] = $item;
            }

            $remaining = array_slice($details, $index + 1);

            return array_merge(...array_map(fn (array $part): array => self::tellApart($part, $remaining), array_values($parts)));
        }

        return array_map(function (array $item): array {
            $item['suffix'] = false;

            return $item;
        }, $group);
    }

    /** @param  array<string, mixed>  $item */
    private static function joined(array $item): string
    {
        return trim($item['base'].' '.implode(' ', (array) $item['suffix']));
    }

    private static function eldanModel(string $name): ?string
    {
        preg_match_all('/[\p{L}\d][\p{L}\d\/\-]*/u', $name, $matches);
        $words = $matches[0];
        $model = [];

        foreach ($words as $index => $word) {
            $isModelWord = mb_strlen($word) >= 2
                && preg_match('/\p{L}/u', $word)
                && $word === mb_strtoupper($word)
                && ! in_array($word, self::NOT_MODEL_WORDS, true);

            if ($isModelWord) {
                // "spodnie Z DZIANINĄ" names a feature, not a model.
                if ($model === [] && $index > 0 && in_array($words[$index - 1], ['Z', 'z'], true)) {
                    return null;
                }

                $model[] = $word;
            } elseif ($model !== [] && in_array($word, ['z', 'Z'], true) && isset($words[$index + 1]) && $words[$index + 1] === mb_strtoupper($words[$index + 1]) && mb_strlen($words[$index + 1]) >= 2) {
                $model[] = 'z';
            } elseif ($model !== []) {
                break;
            }
        }

        return $model === [] ? null : self::title(implode(' ', $model));
    }

    private static function capitalisedModel(string $name, string $brand): ?string
    {
        preg_match_all('/[\p{L}\d][\p{L}\d\-]*/u', $name, $matches);
        $brandWords = array_map(mb_strtolower(...), preg_split('/[\s.]+/u', $brand, flags: PREG_SPLIT_NO_EMPTY) ?: []);
        $model = [];

        foreach (array_slice($matches[0], 1) as $word) {
            if (in_array(mb_strtolower($word), $brandWords, true) || in_array($word, self::NOT_MODEL_WORDS, true)) {
                continue;
            }

            if (preg_match('/^\p{Lu}/u', $word) || ($model !== [] && preg_match('/^\d+$/u', $word))) {
                $model[] = $word;
            } elseif ($model !== []) {
                break;
            }
        }

        return $model === [] ? null : self::title(implode(' ', $model));
    }

    private static function colour(string $lower): ?string
    {
        if (preg_match('/we\s+wzorki\s+(\p{L}+)/u', $lower, $match)) {
            return self::title('we wzorki '.$match[1]);
        }

        if (preg_match('/wzór\s+(\p{L}+)/u', $lower, $match)) {
            return self::title('wzór '.$match[1]);
        }

        // A colour standing alone between commas wins over one in the description:
        // "Żakiet medyczny damski biały ZA 16, mokka, krótki rękaw" is the mokka jacket.
        foreach (array_map(trim(...), explode(',', $lower)) as $segment) {
            if (in_array($segment, self::COLOUR_PHRASES, true) || preg_match(self::COLOUR_WORD, $segment, $match) && $match[1] === $segment) {
                return self::title($segment);
            }
        }

        foreach (self::COLOUR_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                return self::title($phrase);
            }
        }

        return preg_match(self::COLOUR_WORD, $lower, $match) ? self::title($match[1]) : null;
    }

    private static function cut(string $lower): ?string
    {
        $found = [];

        foreach (self::CUTS as $cut) {
            if (preg_match('/(?<!\p{L})'.preg_quote($cut, '/').'\p{L}*/u', $lower, $match)) {
                $found[] = $match[0];
            }
        }

        return $found === [] ? null : self::title(implode(' ', $found));
    }

    /** @param  list<string>  $candidates */
    private static function firstOf(array $candidates, string $name): ?string
    {
        foreach ($candidates as $candidate) {
            if (preg_match('/(?<!\p{L})'.preg_quote($candidate, '/').'(?!\p{L})/u', $name)) {
                return $candidate;
            }
        }

        return null;
    }

    /** "LENA FLEX" -> "Lena Flex", "T-SHIRT" -> "T-Shirt", "MOM FIT z DZIANINĄ" -> "Mom Fit z Dzianiną". */
    private static function title(string $text): string
    {
        $words = array_map(function (string $word): string {
            if (in_array($word, self::ACRONYMS, true) || $word === 'z') {
                return $word;
            }

            return implode('-', array_map(
                fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)).mb_strtolower(mb_substr($part, 1)),
                explode('-', $word),
            ));
        }, explode(' ', $text));

        return implode(' ', $words);
    }
}
