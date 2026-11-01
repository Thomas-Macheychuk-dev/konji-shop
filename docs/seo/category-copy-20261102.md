# Category copy for every populated category — 2 November 2026

## Scope

Before this batch, 59 of 1,119 active categories had reviewed copy in `resources/content/category-copy.pl.php`; the rest fell back to "Przeglądaj kategorię X…". This adds copy for all 1,037 remaining categories that list at least one product. The 23 categories without products are listed in `scripts/seo/category-copy-20261101/empty-categories.json` and get no copy: they need products, merging or removal rather than text.

Database-authored descriptions and the 59 reviewed entries are unchanged and still take precedence. Generated entries follow a marker comment after the reviewed ones and, like them, apply only while the category keeps the expected name.

## Grounding

`sources.json` is a read-only crawl of staging category pages on 1 November: name, breadcrumb path, product count, subcategory names and the first page's product names with their short descriptions. Each entry was written only from those facts, in the tone and shape of the reviewed entries: a meta description (120–155 characters) and two paragraphs (350–750 characters) — what the category contains, then what to compare when choosing, pointing to the product card and manufacturer's instructions.

## Rules enforced by `build.py`

- one entry per source category, slug and name matching exactly, no overlap with reviewed entries;
- lengths and two-paragraph shape;
- no duplicated meta or description; no sentence reused across entries;
- no medical or performance claims (leczy, łagodzi, zapobiega, skutecznie, gwarantuje, klinicznie, polecany, najlepszy …), prices, promotions, delivery, stock, NFZ/refund statements, calls to action or the shop name;
- no site-structure wording (breadcrumb ">", "ścieżka", "najgłębszy poziom", "w strukturze");
- no markup.

Words that are part of the category, subcategory or product names (e.g. "Leczenie bezdechu sennego") are exempt. `test_build.py` (CI) checks every rule for all generated entries and that the PHP file matches `entries.json`; `EditorialCategoryContentTest` renders all reviewed entries and every 25th generated entry.

## Review

The copy is factual and claim-free by construction, but it was not individually reviewed by the shop. Recommended: review the categories that matter most first (by Search Console impressions once available), correcting `entries.json` and rerunning `build.py`. Categories that share a name (35 × "Nowości", several "Temblaki", "Pasy brzuszne" …) each have distinct copy based on their own products, but the duplicated category structure itself is worth consolidating.

## Rebuild

```bash
python3 scripts/seo/category-copy-20261101/build.py
```

It validates `entries.json` against `sources.json` and rewrites only the generated part of `category-copy.pl.php` (idempotent). `--check` validates without writing.
