<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

/**
 * The shop's own orthosis subcategories, by body part, and the name rules that place a product in one.
 *
 * Groups are tried in order on the product name; accessories and positioning products match by name only,
 * the body parts may also match the names of the supplier categories the product sits in. Tuned on the
 * 1,550 staging orthosis products: about 99% find a group; the rest (bras, a cane, face masks) are not
 * orthoses and stay directly under "Ortezy i stabilizatory".
 */
final class OrthosisBodyPart
{
    /** slug => [name, pattern] */
    public const GROUPS = [
        'akcesoria-do-ortez' => ['Akcesoria do ortez', '/wkręt|śrub|zaczep|przedłużacz|klamr|montaż|^pokrowiec|^pelot|zestaw mocując|taśm|rękaw ochronny pod|dodatkowe usztywnienie|wkładk[ai] do ortez|pompk|zawór|granulat|przelotk|zacisk|pasek zębaty|samoszczepn|\bfp-(?:a|em)-|kliny wspierające|podkładka masując/iu'],
        'pozycjonowanie-i-stabilizacja' => ['Pozycjonowanie i stabilizacja', '/bodymap|\bfp-\d|\bneo \d|\bp-ss-|materac|wałek|fotelik|zagłówek|podłokietnik|stolik|uprząż|podkładka antymigracyjna|krążek przeciwodleżyn|– kamizelka/iu'],
        'szyja' => ['Szyja', '/szyj|kołnierz|collar/iu'],
        'kolano' => ['Kolano', '/kolan|rzepk|knee|genu|\bacl\b|\bpcl\b/iu'],
        'kostka-i-stopa' => ['Kostka i stopa', '/kostk|skokow|stop[ayęi]\b|stóp|stopy|pięt|achilles|walker|hallux|ankle|foot|splint|podeszw|obuwi|metatars|opadając|heel|insole|toe spreader/iu'],
        'nadgarstek-i-dlon' => ['Nadgarstek i dłoń', '/nadgarst|dło[nń]|ręki|ręka|rękę|kciuk|przedrami|palc|wrist|thumb|finger|stacka/iu'],
        'lokiec' => ['Łokieć', '/łok[cie]|łokieć|elbow|tennis|golf/iu'],
        'bark-i-ramie' => ['Bark i ramię', '/bark|ramie|ramię|ramion|obojczyk|temblak|shoulder|desault|dessault|kończyny górnej/iu'],
        'kregoslup-i-tulow' => ['Kręgosłup i tułów', '/kręgosł|lędźw|krzyż|piersiow|plec|tułow|tułów|gorset|tlso|\blso\b|korekt|postaw|brzusz|żebr|przepuklin|ciążow|poporod|stomij|prostotrzym|klatk|torako|pas (?:ortopedyczny|wielofunkcyjny)|magnetyczn[ey] pas/iu'],
        'biodro-udo-i-podudzie' => ['Biodro, udo i podudzie', '/biodr|\bud[ao]\b|udow|miednic|pachwin|łydk|podudzi|spojeni|kończyny dolnej|hip\b|thigh|calf/iu'],
    ];

    /** Matched by product name only: supplier category names say little about these. */
    private const NAME_ONLY = ['akcesoria-do-ortez', 'pozycjonowanie-i-stabilizacja'];

    /**
     * @param  list<string>  $categoryNames
     */
    public static function classify(string $productName, array $categoryNames = []): ?string
    {
        foreach (self::GROUPS as $slug => [, $pattern]) {
            if (preg_match($pattern, $productName) === 1) {
                return $slug;
            }
        }

        $joined = implode(' | ', $categoryNames);

        foreach (self::GROUPS as $slug => [, $pattern]) {
            if (! in_array($slug, self::NAME_ONLY, true) && preg_match($pattern, $joined) === 1) {
                return $slug;
            }
        }

        return null;
    }

    public static function isPartyWig(string $productName): bool
    {
        return preg_match('/imprezow|karnawał/iu', $productName) === 1;
    }
}
