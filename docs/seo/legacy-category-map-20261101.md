# Legacy category map review — 1 November 2026

## Why

The approved legacy map covers 664 of the 9,584 live legacy product IDs (1,070 path rules). The other 8,920 live product URLs and all 234 live `-cat-N` category pages return 404 on the new storefront. Every unmapped live product carries an old breadcrumb, and those breadcrumbs resolve to 173 old leaf categories. A reviewed old-category → new-category map is therefore the prerequisite for both category redirects and a category fallback for unmatched products.

This batch produces suggestions and draft decisions only. It installs no redirect and changes no nginx map, route, database record or runtime file. The existing 1,070 product rules, nine aliases and three `/content/` route redirects are unchanged.

## Inputs

`sources.json` freezes both sides so the review is reproducible without private files:

- the 234 live (`200`) legacy category pages from the 13 September legacy inventory, with their old parent trail and the number of live legacy products whose breadcrumb ends in that category (`products`) or below it (`products_subtree`). 13,336 live product paths are attributed;
- the 1,119 active Konji categories (id, parent, name, slug) from the 31 October editorial export.

The 11 legacy categories that already redirected, two 404s and five unfetched rows are out of scope.

## Suggestions

`suggest.py` scores every new category against each old one: leaf-name similarity on accent-folded five-letter word prefixes (65%), agreement between the old parent trail and the new ancestor trail (25%), and an exact slug match (10%). Generic words such as "akcesoria" do not count on their own.

| Confidence | Rule | Old categories | Direct old products |
|---|---|---|---|
| high | identical leaf name, and agreeing parents (or an old department matched to a new department/direct child) | 37 | 3,982 |
| medium | score ≥ 0.45 with some parent agreement | 44 | 4,349 |
| low | score ≥ 0.25, or a name match under an unrelated parent | 115 | 4,687 |
| none | nothing comparable | 38 | 318 |

`resources/seo/ortezka/review/legacy-category-map-20261101/review.csv` is ordered by `legacy_products_subtree`, so the departments and categories that carried most old products come first. Each row gives the best suggestion and two alternatives with full new paths.

Known limitations:

- The new tree contains importer-created duplicates (e.g. `KOŃCZYNA DOLNA` and `Ortopedia > …` branches). The suggestion may be the less useful duplicate; check the alternatives.
- New category product counts were not available offline. A target must be checked for a populated listing before approval.
- Some old categories have no counterpart (e.g. `Stetoskopy`); use the parent decision.

## Decisions

`scripts/seo/legacy-category-map-20261101/decisions.csv` holds one decision per legacy category, separate from the generated suggestions so regenerating `review.csv` never overwrites them:

- `decision`: `map` (use `approved_target_slug`), `parent` (use the decision of the nearest old ancestor category that has its own row) or `gone` (no relevant destination; serve 410);
- `approved_target_slug`: a Konji category slug for `map`, otherwise empty;
- `reviewer_note`, `decided_by`.

The current file is a **draft by Claude (`decided_by=claude-draft`) pending owner review**: 177 `map`, 55 `parent`, 2 `gone`. It resolves 232 legacy categories to 141 distinct Konji categories; the two `gone` rows are empty seasonal gift pages. Draft principles:

- Prefer the curated department branches (e.g. `KOŃCZYNA DOLNA > Ortezy kolana`, `Odzież medyczna i zawodowa > Bluzy medyczne damskie`) over importer-generated duplicates.
- Decide by what the old category actually contained, not its name. "Dla dzieci" held almost only Befado children's shoes and maps to `Obuwie i pielęgnacja stóp`; "Materace z łuską gryki" held buckwheat pillows.
- Old categories without a counterpart (stethoscopes, massage equipment, clinic furniture, air purifiers) use `parent`.
- On 1 November every one of the 141 targets returned 200 on staging with at least one product. Targets showing only one or two products were swapped for a broader populated category where the old category carried many products (e.g. inhalers → `Tlenoterapia`, 87 products; crutches and canes → `Kule, laski, trójnogi i czwórnogi`, 23). Notes record each such choice.

`decisions.py` validates the file and resolves every row: unknown or duplicate IDs, changed paths, invalid decisions, `map` without an existing slug, a slug on a non-`map` row and a root category deferring to a missing parent are rejected. `test_decisions.py` covers those failures and the parent fallback (e.g. pediatric stethoscopes → Stetoskopy → Urządzenia medyczne → `sprzet-medyczny`).

The next batch generates the category redirect map and the product fallback map from the resolved decisions, and re-verifies every target (200 with products, no chains) on both staging and apex at generation time.

## Rebuild

```bash
python3 scripts/seo/legacy-category-map-20261101/suggest.py \
  --inventory storage/app/private/scrapers/seo/ortezka/matching/legacy-inventory.csv \
  --export konji-seo-editorial-review.json
```

Without arguments it regenerates `review.csv` from the committed `sources.json`. `test_suggest.py` checks the frozen scope, reproducibility, that every suggestion is an existing slug and that generic names need agreeing context. `python3 scripts/seo/legacy-category-map-20261101/decisions.py` prints the resolved decision summary.
