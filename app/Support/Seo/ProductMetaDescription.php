<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Illuminate\Support\Str;

/**
 * Builds a product meta description from the product's own description text: whole sentences, no new
 * claims. Used to replace weak imported meta descriptions (empty, very short, supplier breadcrumbs,
 * supplier shop phrasing or generic boilerplate).
 */
final class ProductMetaDescription
{
    public const MIN_LENGTH = 70;

    public const MAX_LENGTH = 155;

    /**
     * Supplier shop wording and generic boilerplate removed from both old and new text.
     *
     * @var array<string, string>
     */
    private const SUPPLIER_PHRASES = [
        '/^Sklep\s+\S+\s+oferuje\s+/iu' => '',
        '/\s+w\s+Shop\s+Medi\b/iu' => '',
        '/\s*Sprawdź opis i parametry produktu w Ortezka\.pl\.?/iu' => '',
    ];

    /**
     * Sentences about the page or its photos ("Kolor … pokazany jest w kwadracie obok zdjęcia") are not product facts.
     */
    private const PAGE_SENTENCE = '/zdjęci|kwadraci|klikn|poniżej|powyżej|w zakładce|przewiń|na stronie|w sklepie/iu';

    public static function isWeak(?string $meta): bool
    {
        $plain = self::squish((string) $meta);

        if (mb_strlen($plain) < self::MIN_LENGTH) {
            return true;
        }

        if (self::removeSupplierPhrases($plain) !== $plain) {
            return true;
        }

        // Breadcrumb-like imports ("Vermeiren Sprzęt przeciwodleżynowy Poduszki … VICAIR ACTIVE O2") have no sentence.
        return preg_match('/[.!?…]/u', $plain) !== 1 && mb_strlen($plain) < 120;
    }

    public static function build(?string $descriptionHtml, ?string $shortDescription, string $name): ?string
    {
        foreach ([$descriptionHtml, $shortDescription] as $source) {
            $candidate = self::fromText(self::units((string) $source), $name);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $units
     */
    private static function fromText(array $units, string $name): ?string
    {
        $sentences = [];

        foreach ($units as $unit) {
            foreach (preg_split('/(?<=[.!?…])\s+(?=[\p{Lu}0-9„"])/u', $unit) ?: [] as $sentence) {
                $sentence = trim($sentence);

                // Skip fragments, lines that only repeat the product name and sentences about the page itself.
                if (mb_strlen($sentence) < 25
                    || mb_strtolower(rtrim($sentence, '.')) === mb_strtolower($name)
                    || preg_match(self::PAGE_SENTENCE, $sentence) === 1) {
                    continue;
                }

                $sentences[] = preg_match('/[.!?…]$/u', $sentence) === 1 ? $sentence : $sentence.'.';
            }
        }

        $text = '';

        foreach ($sentences as $sentence) {
            $next = $text === '' ? $sentence : $text.' '.$sentence;

            if (mb_strlen($next) > self::MAX_LENGTH) {
                break;
            }

            $text = $next;
        }

        if ($text === '' && $sentences !== []) {
            // A single long first sentence is cut at a word boundary.
            $text = rtrim(Str::limit($sentences[0], self::MAX_LENGTH - 1, ''), ' ,;:-–').'…';
        }

        return mb_strlen($text) >= self::MIN_LENGTH ? $text : null;
    }

    /**
     * Visible description text as paragraph/list units, without headings, tables or scripts.
     *
     * @return list<string>
     */
    private static function units(string $html): array
    {
        $html = preg_replace('#<(h[1-6]|table|script|style)\b[^>]*>.*?</\1>#isu', ' ', $html) ?? '';
        $html = preg_replace('#<br\s*/?>|</(p|li|div|ul|ol|tr)>#iu', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return array_values(array_filter(array_map(
            fn (string $line): string => self::removeSupplierPhrases(self::squish($line)),
            preg_split('/\n+/u', $text) ?: [],
        )));
    }

    private static function removeSupplierPhrases(string $text): string
    {
        $clean = preg_replace(array_keys(self::SUPPLIER_PHRASES), array_values(self::SUPPLIER_PHRASES), $text) ?? $text;

        return $clean === $text ? $text : Str::ucfirst(trim($clean));
    }

    private static function squish(string $text): string
    {
        // Imported copy often has a space before punctuation ("sprawiają , że").
        return trim(preg_replace(['/\s+/u', '/\s+([,.;:!?])/u'], [' ', '$1'], $text) ?? '');
    }
}
