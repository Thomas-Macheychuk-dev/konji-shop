<?php

declare(strict_types=1);

namespace App\Services\Peruka;

/**
 * In several peruka.pl lines (Hair Up, Delicious, Coffee) the main product photo is a close-up of the hair
 * colour, not the wig. A list of wigs then shows a page of hair samples. The owner wants the hairstyle first
 * (16 November 2026): the model photo comes first, the colour sample stays in the gallery.
 *
 * Peruka.pl names its files: a colour sample is a colour code ("15-23-6R.jpg", "61.jpg", "6-8-4R-hh.jpg") or a
 * stock number ("PER001757-52732.jpg", "Hai001468-69110.jpg"). Model photos are named after the model
 * ("Pudding-01.jpg", "JELLY-8-14-6R-front-900x900.jpg"); Ellen Wille photos ("ELL000421-17677.jpg") already
 * show the wig. The cap ("baza-tresy-…") and length ("-dlugosci.webp") charts are shared by every product.
 */
final class PerukaPhotoOrder
{
    public static function isColourSample(?string $url): bool
    {
        $stem = self::stem($url);

        return $stem !== ''
            && (preg_match('/^(?:per|hai)\d{4,}-\d+$/i', $stem) === 1
                || preg_match('/^\d+(?:[-_]\d*[a-z]{0,2})*$/i', $stem) === 1);
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

        foreach ($urls as $index => $url) {
            if (! self::isColourSample($url) && ! self::isChart($url)) {
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
