<?php

declare(strict_types=1);

namespace App\Support\Seo;

/**
 * A supplier shop's own sales line, copied with its meta description, e.g. seni24.pl's
 * "Kup Pieluchy anatomiczne Seni San Maxi za 21,69 zł | Darmowa dostawa od 119 zł - już w 24h | Zobacz inne z kategorii …".
 * It names the supplier's price and delivery terms, which are not this shop's, so it must not reach a product page.
 */
final class ShopPromoText
{
    public const PATTERN = '/\bKup\b.{0,250}?\bza\s+\d[\d\s]*(?:[,.]\d{1,2})?\s*zł|Darmowa dostawa od|już w 24\s*h|Zobacz inne z kategorii/iu';

    public static function matches(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_match(self::PATTERN, $plain) === 1;
    }
}
