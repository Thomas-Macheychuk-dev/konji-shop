<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Illuminate\Support\Str;

/**
 * Builds a product meta description from the product's own description text: whole sentences, no new
 * claims. Used to replace weak imported meta descriptions (empty, very short, supplier breadcrumbs,
 * supplier shop phrasing, scraped page tabs or generic boilerplate).
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
     * Sentences about the page, its photos or its downloads ("Kolor … pokazany jest w kwadracie obok zdjęcia",
     * "CE_Vicky_2024-07-23_pl.pdf") are not product facts.
     */
    private const PAGE_SENTENCE = '/zdjęci|kwadraci|modelk|klikn|poniżej|powyżej|w zakładce|przewiń|na stronie|w (naszym )?sklepie|skontaktuj się|user manual|pobierz|\.(pdf|docx?|xlsx?|zip)\b/iu';

    /**
     * Manufacturer, importer, advertiser and contact blocks ("Producent: …, ul. …, 03-310 Warszawa, www…") are not product descriptions.
     */
    private const CONTACT_SENTENCE = '/\b(producent|wytwórca|dystrybutor|importer|podmiot odpowiedzialny|podmiot prowadzący reklamę)\b\s*:|www\.|https?:|@|e-?mail|\btel\.|\bul\.\s|\b\d{2}-\d{3}\b|\bsp\.\s*z\s*o\.?\s*o|spółka jawna/iu';

    /**
     * Supplier page tabs and widgets scraped along with the description ("Opis Wskazania Dostępne rozmiary …").
     * Phrases such as "dostępne rozmiary" alone also occur in real descriptions, so only the tab labels count.
     */
    private const PAGE_CHROME = '/\bOpis Wskazania\b|Powiązane produkty|Masz jakieś pytania|Detale produktu/iu';

    /**
     * A list item's trailing comma or colon followed by an added full stop ("łydki,. Niewydolność").
     */
    private const BROKEN_PUNCTUATION = '/\s*[,;:]\s*\./u';

    public static function isWeak(?string $meta): bool
    {
        $plain = self::squish((string) $meta);

        if (mb_strlen($plain) < self::MIN_LENGTH
            || preg_match(self::PAGE_CHROME, $plain) === 1
            || preg_match(self::BROKEN_PUNCTUATION, $plain) === 1) {
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
     * A stored description as rendered: plain text with broken list punctuation repaired. Valid long
     * descriptions are kept whole rather than truncated.
     */
    public static function clean(string $meta): string
    {
        return preg_replace(self::BROKEN_PUNCTUATION, '.', self::squish(strip_tags($meta))) ?? '';
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

                // Skip fragments, lines that only repeat the product name, sentences about the page itself and
                // lead-ins to a list ("Dzięki bazie peruki zyskujesz:").
                if (mb_strlen($sentence) < 25
                    || mb_strtolower(rtrim($sentence, '.')) === mb_strtolower($name)
                    || preg_match(self::PAGE_SENTENCE, $sentence) === 1
                    || preg_match(self::CONTACT_SENTENCE, $sentence) === 1
                    || preg_match(self::PAGE_CHROME, $sentence) === 1
                    || str_ends_with($sentence, ':')
                    || self::isShouted($sentence)) {
                    continue;
                }

                // List items are often lowercase fragments ("miękkie, naturalne dziąsła") ending in a comma.
                $sentence = Str::ucfirst(rtrim($sentence, ' ,;'));
                $sentences[] = preg_match('/[.!?…]$/u', $sentence) === 1 ? $sentence : $sentence.'.';
            }
        }

        $text = '';

        foreach ($sentences as $sentence) {
            $next = $text === '' ? $sentence : $text.' '.$sentence;

            if (mb_strlen($next) > self::MAX_LENGTH) {
                // Too little so far to stand alone: the sentence that does not fit is cut at a word boundary.
                if (mb_strlen($text) < self::MIN_LENGTH) {
                    $text = rtrim(Str::limit($next, self::MAX_LENGTH - 1, '', preserveWords: true), ' ,;:-–').'…';
                }

                break;
            }

            $text = $next;
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
        // The Vermeiren importer appends manual/video links and a medical-device notice; neither describes the product.
        $html = preg_replace('#<section class="vermeiren-(documents|medical-notice)">.*?</section>#isu', ' ', $html) ?? '';
        $html = preg_replace('#<(h[1-6]|table|script|style)\b[^>]*>.*?</\1>#isu', ' ', $html) ?? '';
        // A paragraph holding only a short bold line ("<p><strong>Co go wyróżnia?</strong></p>") is a heading too.
        $html = preg_replace('#<p\b[^>]*>\s*(?:<span\b[^>]*>\s*)?<(strong|b)\b[^>]*>[^<]{1,120}</\1>\s*(?:</span>\s*)?</p>#iu', "\n", $html) ?? '';
        $html = preg_replace('#<br\b[^>]*>|</(p|li|div|ul|ol|tr)>#iu', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Bullet characters mark list items, inline or at the start of a line ("• wyposażone w …").
        $text = str_replace(['•', '▪', '●'], "\n", $text);

        return array_values(array_filter(array_map(
            fn (string $line): string => self::removeSupplierPhrases(self::squish($line)),
            preg_split('/\n+/u', $text) ?: [],
        )));
    }

    /**
     * All-caps slogans ("POCZUJESZ ULGĘ W BÓLU STÓP") are headings, not sentences.
     */
    private static function isShouted(string $sentence): bool
    {
        return preg_match('/\p{Ll}/u', $sentence) !== 1 && preg_match('/\p{Lu}{4}/u', $sentence) === 1;
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
