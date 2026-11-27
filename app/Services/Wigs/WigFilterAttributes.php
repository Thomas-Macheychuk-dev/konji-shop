<?php

declare(strict_types=1);

namespace App\Services\Wigs;

use App\Enums\AttributeDisplayType;
use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Support\Str;

/**
 * The wig filters, as on peruka.pl (owner, 27 November 2026): hair length, structure, colour, base and hair type,
 * plus the topper and hair-piece filters of peruka.pl's other categories. Each is a product attribute with a
 * filter_position, so listings offer it wherever the listed products have values.
 *
 * Both wig suppliers map onto the same attributes: peruka.pl through its filter ids, twojaperuka.pl through its
 * subcategories. Colour belongs to each colour product; the other filters describe the model, so they are copied
 * to every colour of a peruka.pl model (colour_group).
 */
final class WigFilterAttributes
{
    public const LENGTH = 'dlugosc-fryzury';

    public const STRUCTURE = 'struktura-wlosow';

    public const COLOUR = 'kolor-wlosow';

    public const BASE = 'baza-peruki';

    public const HAIR_TYPE = 'rodzaj-wlosa';

    public const TOPPER_SIZE = 'wielkosc-toppera';

    public const ROOT_COLOUR = 'kolor-z-odrostem';

    public const ATTACHMENT = 'rodzaj-mocowania';

    public const HAIRSTYLE = 'rodzaj-fryzury';

    /** Attribute slug => name and filter position. */
    public const ATTRIBUTES = [
        self::LENGTH => ['name' => 'Długość fryzury', 'position' => 10],
        self::STRUCTURE => ['name' => 'Struktura włosów', 'position' => 20],
        self::COLOUR => ['name' => 'Kolor włosów', 'position' => 30],
        self::ROOT_COLOUR => ['name' => 'Kolor z odrostem', 'position' => 35],
        self::BASE => ['name' => 'Baza', 'position' => 40],
        self::HAIR_TYPE => ['name' => 'Rodzaj włosa', 'position' => 50],
        self::TOPPER_SIZE => ['name' => 'Wielkość toppera', 'position' => 60],
        self::ATTACHMENT => ['name' => 'Rodzaj mocowania', 'position' => 70],
        self::HAIRSTYLE => ['name' => 'Rodzaj fryzury', 'position' => 80],
    ];

    /**
     * peruka.pl filter id ("app_product_filter[12][…]") => our attribute. Filter 15, "Linia rozmyta", has the
     * single value "Tak" and is left out.
     */
    public const PERUKA_FILTERS = [
        1 => self::LENGTH,
        17 => self::STRUCTURE,
        12 => self::COLOUR,
        16 => self::ROOT_COLOUR,
        6 => self::BASE,
        7 => self::HAIR_TYPE,
        8 => self::TOPPER_SIZE,
        25 => self::ATTACHMENT,
        26 => self::HAIRSTYLE,
    ];

    /** Filters that describe one colour product, not the whole model. */
    public const PER_COLOUR = [self::COLOUR, self::ROOT_COLOUR];

    /**
     * peruka.pl shows these values as pictures without names: the length silhouettes and the hair samples. The
     * names come from the products each one lists (27 November 2026).
     */
    public const PERUKA_PICTURE_LABELS = [
        1 => [103 => 'Krótkie', 104 => 'Średnie', 106 => 'Długie'],
        12 => [
            53 => 'Jasny blond',
            52 => 'Ciemny blond',
            122 => 'Rudy',
            51 => 'Brąz',
            50 => 'Ciemny brąz',
            54 => 'Kasztan i burgund',
            49 => 'Czarny',
            55 => 'Siwy',
        ],
    ];

    /** Colour value => swatch colour for the filter. */
    public const COLOUR_SWATCHES = [
        'Jasny blond' => '#e6cf98',
        'Ciemny blond' => '#b48f5a',
        'Rudy' => '#b4561f',
        'Brąz' => '#7b5233',
        'Ciemny brąz' => '#3e2a1e',
        'Kasztan i burgund' => '#6d2a1f',
        'Czarny' => '#161616',
        'Siwy' => '#bdbdbd',
    ];

    /**
     * twojaperuka.pl subcategory path => attribute and value. Its colour subcategories only know blond, brown and
     * red, so a product's name decides its colour first (colourFromName()).
     */
    public const TWOJAPERUKA_CATEGORIES = [
        '/pl/c/Peruki-krotkie/53' => [self::LENGTH, 'Krótkie'],
        '/pl/c/Peruki-do-ramion/54' => [self::LENGTH, 'Średnie'],
        '/pl/c/Peruki-dlugie/55' => [self::LENGTH, 'Długie'],
        '/pl/c/Peruki-syntetyczne/48' => [self::HAIR_TYPE, 'Włosy syntetyczne'],
        '/peruki-naturalne-tanie-z-prawdziwych-naturalnych-wlosow' => [self::HAIR_TYPE, 'Włosy naturalne'],
        '/peruki_w-odcieniach_blond_twojaperuka.pl' => [self::COLOUR, 'Jasny blond'],
        '/brazowe_peruki_twoja_peruka_pl' => [self::COLOUR, 'Brąz'],
        '/peruki_damskie_odcienie_rude' => [self::COLOUR, 'Rudy'],
    ];

    /** Value labels in the order the filters list them: lengths short to long, colours light to dark. */
    private const VALUE_ORDER = [
        'Krótkie', 'Średnie', 'Długie',
        'Jasny blond', 'Ciemny blond', 'Rudy', 'Brąz', 'Ciemny brąz', 'Kasztan i burgund', 'Czarny', 'Siwy',
    ];

    /**
     * The colour group a product name describes, for colours peruka.pl's filter does not list (it shows one colour
     * per model) and for twojaperuka.pl. Roots are ignored: "blond z ciemnym brązowym odrostem" is blond.
     */
    public static function colourFromName(string $name): ?string
    {
        $name = mb_strtolower($name);
        $name = preg_replace('/\bz\s+(?:[\p{L}-]+\s+){0,3}odrost\p{L}*/u', ' ', $name) ?? $name;

        $rules = [
            '/siw|szpakow/u' => 'Siwy',
            '/czarn/u' => 'Czarny',
            '/burgund|bordo|bordow|kasztan|wiśni|mahoń|winn/u' => 'Kasztan i burgund',
            '/\brud(?:y|a|e|ego|ej)?\b|\brudo|miedzi|rdzaw/u' => 'Rudy',
            '/ciemn\p{L}*[\s-]+brąz|ciemnobrąz|czekolad|espresso/u' => 'Ciemny brąz',
            '/brąz|brązow/u' => 'Brąz',
            '/ciemn\p{L}*[\s-]+blond|piaskow|karmel|miodow|bursztyn|złocist|orzechow/u' => 'Ciemny blond',
            '/blond|platyn|perłow|szampan/u' => 'Jasny blond',
        ];

        foreach ($rules as $pattern => $colour) {
            if (preg_match($pattern, $name) === 1) {
                return $colour;
            }
        }

        return null;
    }

    /** The attribute for a filter slug, created or brought up to date. */
    public function attribute(string $slug): Attribute
    {
        $definition = self::ATTRIBUTES[$slug];
        $attribute = Attribute::query()->firstOrNew(['slug' => $slug]);
        $attribute->fill([
            'name' => $definition['name'],
            'display_type' => $slug === self::COLOUR ? AttributeDisplayType::COLOR_SWATCH : AttributeDisplayType::SELECT,
            'filter_position' => $definition['position'],
        ]);

        if (! $attribute->exists) {
            $attribute->external_attribute_id = 'wig-filter-'.$slug;
        }

        $attribute->save();

        return $attribute;
    }

    /** The value of a filter attribute, created when missing. */
    public function value(Attribute $attribute, string $label, int $sortOrder = 0): AttributeValue
    {
        $label = Str::limit(trim($label), 190, '');
        $known = array_search($label, self::VALUE_ORDER, true);

        return AttributeValue::query()->firstOrCreate(
            ['attribute_id' => $attribute->id, 'slug' => Str::slug($label) ?: substr(sha1($label), 0, 12)],
            [
                'value' => $label,
                'external_option_id' => null,
                'swatch_value' => self::COLOUR_SWATCHES[$label] ?? null,
                'sort_order' => $known === false ? 100 + $sortOrder : $known,
            ],
        );
    }
}
