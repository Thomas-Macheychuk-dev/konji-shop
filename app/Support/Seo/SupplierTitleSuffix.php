<?php

declare(strict_types=1);

namespace App\Support\Seo;

/**
 * Supplier product pages end their <title> with the supplier's site branding, which importers copy into
 * products.seo_title. Keep the manufacturer brand and drop countries, taglines and shop wording; drop
 * retailer-only suffixes. Unlisted suffixes (e.g. "- Antar", "| Timago") are already just a brand.
 */
final class SupplierTitleSuffix
{
    /**
     * Trailing suffix pattern => replacement, matched against the end of the title.
     *
     * @var array<string, string>
     */
    public const RULES = [
        'vermeiren' => ' | Vermeiren',
        'reh4mat' => ' | Reh4Mat',
        'relaxsan' => ' | RelaxSan',
        'microlife' => ' | Microlife',
        'zamst' => ' | Zamst',
        'medi' => ' | medi',
        'apolonia' => ' | Apolonia',
        'novicare' => ' | NOVICARE',
        'retailer' => '',
        'shop' => '',
    ];

    /**
     * @var array<string, string>
     */
    private const PATTERNS = [
        'vermeiren' => '/\s*[|\-–]\s*Vermeiren Polska$/u',
        'reh4mat' => '/\s*\|\s*Reh4Mat\s*[-–]\s*Wytwórca nowoczesnych przedmiotów ortopedycznych$/u',
        'relaxsan' => '/\s*\|\s*Sklep RelaxSan$/u',
        'microlife' => '/\s*[-–]\s*Microlife AG$/u',
        'zamst' => '/\s*[-–]\s*Zamst Polska$/u',
        'medi' => '/\s*\|\s*medi sklep internetowy$/u',
        'apolonia' => '/\s*\|\s*Sklep z odzieżą medyczną Warszawa Apolonia$/u',
        'novicare' => '/\s*[-–]\s*NOVICARE\s*[-–]\s*Dystrybutor Specjalistycznych Produktów Ortopedycznych$/u',
        'retailer' => '/\s*[|\-–]\s*(?:Peruka\.pl|TwojaPeruka\.pl|GaleriaZdrowia\.pl)$/u',
        'shop' => '/\s*\|\s*Sklep$/u',
    ];

    public static function normalize(?string $title): ?string
    {
        return $title === null ? null : self::apply($title)[0];
    }

    /**
     * @return array{0: string, 1: string|null} the normalized title and the matched rule, if any
     */
    public static function apply(string $title): array
    {
        foreach (self::PATTERNS as $rule => $pattern) {
            $normalized = preg_replace($pattern, self::RULES[$rule], $title, 1, $count);

            if ($count === 1 && is_string($normalized) && trim($normalized) !== '') {
                return [$normalized, $rule];
            }
        }

        return [$title, null];
    }
}
