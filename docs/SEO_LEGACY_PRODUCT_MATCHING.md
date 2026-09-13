# SEO-02B legacy product matching evidence

`php artisan seo:match-legacy-products` compares the frozen SEO-01 legacy product inventory with the production SEO-02A target product inventory.

The command is deliberately evidence-only:

- database writes: **none**;
- redirects/runtime changes: **none**;
- redirects approved: **always zero**.

It does not treat an exact SKU collision as sufficient evidence. Production data contains globally unique Konji variant SKUs, but old Ortezka numeric `Indeks` values can collide with unrelated supplier SKU namespaces. The report therefore keeps variant-SKU, parent-SKU and exact normalized-name evidence separate and surfaces conflicts explicitly.

Classifications:

- `exact_identifier_and_name`: an exact unique normalized name agrees with at least one unique identifier candidate;
- `identifier_agreement`: unique variant-SKU and unique external-parent-SKU evidence point to the same product;
- `identifier_conflict`: those two identifier sources point to different products;
- `parent_candidate`: unique external-parent-SKU candidate only;
- `variant_candidate`: unique variant-SKU candidate only;
- `exact_name_only`: unique exact normalized name candidate only;
- `duplicate_legacy_index`, `missing_legacy_index`, `unmatched`: manual/research cohorts.

No classification is a production 301 approval by itself. SEO-02C will turn reviewed evidence into an explicit approved mapping cohort.
