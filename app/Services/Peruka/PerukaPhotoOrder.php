<?php

declare(strict_types=1);

namespace App\Services\Peruka;

/**
 * In several peruka.pl lines (Hair Up, Delicious, Coffee) the main product photo is a close-up of the hair
 * colour, not the wig. A list of wigs then shows a page of hair samples. The owner wants the hairstyle first
 * (16 November 2026): the model photo comes first, the colour sample stays in the gallery.
 *
 * Peruka.pl names its files: a colour sample is a colour code ("15-23-6R.jpg", "61.jpg", "6-8-4R-hh.jpg"), or
 * a stock number ("PER001757-52732.jpg", "Hai001468-69110.jpg") when it is the main photo. Gallery photos
 * with stock numbers ("Hai001421-68241.jpg") show the wig, as do photos named after the model
 * ("Pudding-01.jpg", "JELLY-8-14-6R-front-900x900.jpg") and Ellen Wille photos ("ELL000421-17677.jpg"). The
 * cap ("baza-tresy-…") and length ("-dlugosci.webp") charts are shared by every product.
 */
final class PerukaPhotoOrder
{
    /** A colour code, or a stock number. A stock number is a colour sample only as the main photo. */
    public static function isColourSample(?string $url): bool
    {
        return self::isColourCode($url) || self::isStockNumber($url);
    }

    public static function isColourCode(?string $url): bool
    {
        return preg_match('/^\d+(?:[-_]\d*[a-z]{0,2})*$/i', self::stem($url)) === 1 && ! self::isStockNumber($url);
    }

    /**
     * "PER001757-52732", "Hai001468-69110", and bare "002095-17622" or "0277-17386": a leading zero or four
     * digits, then at least three. Colour codes have shorter groups ("830-131-14", "26-27-19-8R").
     */
    public static function isStockNumber(?string $url): bool
    {
        return preg_match('/^(?:(?:per|hai)\d{4,}-\d+|(?:0\d*|\d{4,})-\d{3,})$/i', self::stem($url)) === 1;
    }

    /**
     * The colour sample among photos in order: the second photo after order() moved a model photo in front of it,
     * otherwise the first. The second is checked first: a model photo moved in front can have a stock number too
     * ("Hai001470-69096.jpg" before the sample "Hai001470-69111.jpg").
     *
     * @param  list<string|null>  $urls
     */
    public static function colourSampleIndex(array $urls): ?int
    {
        foreach ([1, 0] as $index) {
            if (isset($urls[$index]) && self::isColourSample($urls[$index])) {
                return $index;
            }
        }

        return null;
    }

    public static function isChart(?string $url): bool
    {
        return preg_match('/baza|tresy|dlugosc/i', self::stem($url)) === 1;
    }

    /**
     * The order of the photos with the first model photo in front, when the first photo is a colour sample.
     * Otherwise the order is unchanged.
     *
     * @param  list<string|null>  $urls
     * @return list<int>
     */
    public static function order(array $urls): array
    {
        $indexes = array_keys($urls);

        if ($urls === [] || ! self::isColourSample($urls[0])) {
            return $indexes;
        }

        foreach (array_slice($urls, 1, preserve_keys: true) as $index => $url) {
            if (! self::isColourCode($url) && ! self::isChart($url)) {
                return [$index, ...array_values(array_diff($indexes, [$index]))];
            }
        }

        return $indexes;
    }

    public static function fileName(?string $url): string
    {
        return basename(rawurldecode((string) parse_url((string) $url, PHP_URL_PATH)));
    }

    private static function stem(?string $url): string
    {
        $stem = pathinfo(self::fileName($url), PATHINFO_FILENAME);

        return preg_replace('/^(?:large|big|gallery)_/i', '', $stem) ?? $stem;
    }
}
