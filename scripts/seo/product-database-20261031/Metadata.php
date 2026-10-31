<?php

declare(strict_types=1);

final class ProductSeoMetadata
{
    public static function plain(?string $text): string
    {
        $text = html_entity_decode(html_entity_decode($text ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~isu', '', $text ?? '') ?? '';
        $text = preg_replace('~</?(?:p|div|li|br|h[1-6])\b[^>]*>~iu', ' ', $text) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\s\x{00a0}\x{200b}\x{feff}\x{3164}\x{1160}]+/u', ' ', $text) ?? '');
    }

    public static function placeholder(string $text): bool
    {
        return $text === '' || (bool) preg_match('/^(?:0|[-–—.]+|n\/?a|null|undefined|brak(?: opisu)?|opis produktu|produkt|test|strona główna|sklep internetowy)$/iu', $text);
    }

    public static function foreignReference(string $text): bool
    {
        if (preg_match('/galeria\s*zdrowia|seni24|\b(?:medi\s+)?sklep\s+internetowy\b/iu', $text)) {
            return true;
        }
        preg_match_all('~\b(?:https?://)?(?:www\.)?[\pL\d-]+(?:\.[\pL\d-]+)*\.(?:pl|com|eu|net|org|de|cz|nl|uk|shop|store)\b~iu', $text, $matches);
        foreach ($matches[0] as $domain) {
            if (! preg_match('~^(?:https?://)?(?:www\.)?ortezka\.pl$~i', $domain)) {
                return true;
            }
        }

        return false;
    }

    public static function promotional(string $text): bool
    {
        return (bool) preg_match('/\d+(?:[,.]\d{1,2})?\s*(?:zł\b|PLN\b)|darmow\w*\s+(?:dostaw\w*|wysyłk\w*)|(?:dostaw\w*|wysyłk\w*|już)\s+(?:w|do)\s*\d+\s*(?:h\b|godzin|dni\b)/iu', $text);
    }

    public static function cleanTitle(string $text): string
    {
        $text = self::plain($text);
        $text = preg_replace('/\s*[|–—-]\s*(?:medi\s+)?sklep\s+internetowy\b.*$/iu', '', $text) ?? '';
        $text = preg_replace('/galeria\s*zdrowia(?:\.pl)?|seni24(?:\.pl)?/iu', '', $text) ?? '';
        $text = preg_replace('~\b(?:https?://)?(?:www\.)?[\pL\d-]+(?:\.[\pL\d-]+)*\.(?:pl|com|eu|net|org|de|cz|nl|uk|shop|store)(?:/\S*)?~iu', '', $text) ?? '';

        return trim(preg_replace('/[\s|–—-]+$/u', '', self::plain($text)) ?? '');
    }

    // Preserve identifying suffixes rather than cutting a model or dimension off.
    public static function fit(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $tail = min(65, (int) floor($limit / 3));

        return rtrim(mb_substr($text, 0, $limit - $tail - 3)).' … '.ltrim(mb_substr($text, -$tail));
    }

    /** @return array{after: array{seo_title: string, seo_description: string}, reasons: list<string>, minimal_description: bool} */
    public static function propose(array $product, array $attributes = [], array $reviewed = []): array
    {
        $reasons = [];
        $oldTitle = self::plain($product['seo_title'] ?? null);
        $name = self::cleanTitle($product['name']);
        if (self::placeholder($name)) {
            $name = '';
        }
        $title = self::cleanTitle($oldTitle);
        $entry = $reviewed[$product['id']] ?? null;
        if ($entry !== null) {
            foreach (['name', 'slug', 'external_source', 'external_id', 'seo_title'] as $field) {
                if (($product[$field] ?? null) !== $entry[$field]) {
                    $entry = null;
                    break;
                }
            }
        }
        if ($entry !== null) {
            $title = self::plain($entry['title']);
            $reasons[] = 'persist_reviewed_title';
        } elseif (self::placeholder($oldTitle) || self::placeholder($title) || self::promotional($oldTitle)) {
            $title = $name;
            $reasons[] = $oldTitle === '' ? 'fill_title' : 'replace_promotional_or_generic_title';
        } elseif ($title !== ($product['seo_title'] ?? null)) {
            $reasons[] = 'clean_title';
        }
        $title = self::fit($title, 255);
        if (self::placeholder($title) || self::foreignReference($title)) {
            throw new RuntimeException('No safe title for product '.$product['id']);
        }

        $description = self::plain($product['seo_description'] ?? null);
        $minimal = false;
        $disclaimer = (bool) preg_match('/^(?:to jest wyrób medyczny\b|(?:używaj|używać)\b.*instrukc)/iu', $description);
        if (self::placeholder($description) || self::foreignReference($description) || self::promotional($description) || $disclaimer) {
            $reasons[] = $description === '' ? 'fill_description' : 'replace_contaminated_description';
            $subject = $entry !== null ? $title : ($name !== '' ? $name : $title);
            $description = rtrim(self::fit($subject, 140), ' .').'.';
            $facts = 0;
            foreach (['Model', 'Symbol', 'Numer katalogowy', 'Materiał', 'Pojemność', 'Kolor', 'Marka', 'Producent', 'Wymiary'] as $label) {
                $values = array_values(array_unique(array_map(self::plain(...), $attributes[$label] ?? [])));
                if (count($values) !== 1) {
                    continue;
                }
                $value = $values[0];
                if ($value === '' || mb_strlen($value) > 48 || self::foreignReference($value) || self::promotional($value)
                    || ! preg_match('~^[\pL\pN\s.,+%()/×-]+$~u', $value)
                    || preg_match('/^(?:0+(?:[,.]0+)?)(?:\s*(?:cm|mm|m|ml|l|g|kg))?$/iu', $value)
                    || ($label === 'Kolor' && preg_match('/[,\/]/', $value))
                    || mb_stripos($subject, $value) !== false) {
                    continue;
                }
                $candidate = $description.' '.$label.': '.$value.'.';
                if (mb_strlen($candidate) <= 160) {
                    $description = $candidate;
                    $facts++;
                }
                if ($facts === 2) {
                    break;
                }
            }
            if ($facts === 0) {
                $minimal = true;
                $suffix = ' Sprawdź opis i parametry produktu w Ortezka.pl.';
                if (mb_strlen($description.$suffix) <= 160) {
                    $description .= $suffix;
                }
            }
        } elseif ($description !== ($product['seo_description'] ?? null)) {
            $reasons[] = 'normalise_description';
        }
        if ($description === '' || self::foreignReference($description) || self::promotional($description)) {
            throw new RuntimeException('No safe description for product '.$product['id']);
        }

        return ['after' => ['seo_title' => $title, 'seo_description' => $description], 'reasons' => $reasons, 'minimal_description' => $minimal];
    }
}
