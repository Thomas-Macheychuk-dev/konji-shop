<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Models\AttributeValue;
use App\Models\Product;

/**
 * Brand and GTIN for Product structured data, taken only from what the supplier import stored:
 * the "Marka"/"Producent" attribute and an EAN printed in the description. Anything ambiguous is
 * left out, because a wrong identifier is worse than none.
 */
final class ProductIdentifiers
{
    /**
     * Attribute names in order of preference.
     */
    private const BRAND_ATTRIBUTES = ['marka', 'producent'];

    /**
     * Legal-form suffixes ("Antar Spółka Jawna", "Reha Fund sp. z o. o.") are not part of the brand.
     */
    private const LEGAL_SUFFIX = '/[\s,]+(sp\.?\s*z\s*o\.?\s*o\.?|spółka\s+(jawna|z\s+ograniczoną\s+odpowiedzialnością|komandytowa|akcyjna|cywilna)|sp\.?\s*j\.?|sp\.?\s*k\.?|s\.\s*a\.?|s\.?c\.?|gmbh|s\.?r\.?l\.?|ltd\.?|inc\.?|b\.?v\.?|n\.?v\.?)\s*$/iu';

    /**
     * An EAN/GTIN label followed by its digits, in a table or in running text ("Kod EAN: 5903…").
     */
    private const EAN = '/\b(?:kod\s+)?(?:ean(?:-?13)?|gtin)\s*:?\s*(\d{8,14})\b/iu';

    /**
     * @param  list<string>  $notBrands  Names that must never be presented as the brand, such as the shop itself.
     */
    public static function brand(Product $product, array $notBrands = []): ?string
    {
        $values = $product->relationLoaded('attributeValues') ? $product->attributeValues : $product->attributeValues()->with('attribute')->get();

        foreach (self::BRAND_ATTRIBUTES as $attributeName) {
            $value = $values->first(fn (AttributeValue $value): bool => mb_strtolower(trim((string) $value->attribute?->name)) === $attributeName);
            $brand = self::brandName((string) $value?->value);

            if ($brand !== null && ! self::isExcluded($brand, $notBrands)) {
                return $brand;
            }
        }

        return null;
    }

    public static function brandName(string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $name = trim((string) preg_replace(self::LEGAL_SUFFIX, '', $name), " \t,.-");

        return $name === '' ? null : $name;
    }

    /**
     * The one valid GTIN printed in the description, for a product sold as a single variant. Products with
     * several variants (sizes, colours) have one GTIN each, so a description-level EAN cannot be trusted.
     */
    public static function gtin(?string $descriptionHtml, int $variantCount): ?string
    {
        if ($variantCount !== 1 || blank($descriptionHtml)) {
            return null;
        }

        $text = html_entity_decode(strip_tags((string) preg_replace('/<[^>]+>/u', ' $0 ', (string) $descriptionHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all(self::EAN, $text, $matches);

        $gtins = array_values(array_unique(array_filter($matches[1], self::isValidGtin(...))));

        return count($gtins) === 1 ? $gtins[0] : null;
    }

    public static function isValidGtin(string $gtin): bool
    {
        if (preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $gtin) !== 1 || (int) $gtin === 0) {
            return false;
        }

        $digits = array_map('intval', str_split($gtin));
        $check = array_pop($digits);
        $sum = 0;

        foreach (array_reverse($digits) as $position => $digit) {
            $sum += $digit * ($position % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }

    /**
     * @param  list<string>  $notBrands
     */
    private static function isExcluded(string $brand, array $notBrands): bool
    {
        foreach ($notBrands as $notBrand) {
            $notBrand = self::brandName($notBrand);

            if ($notBrand !== null && mb_strtolower($notBrand) === mb_strtolower($brand)) {
                return true;
            }
        }

        return false;
    }
}
