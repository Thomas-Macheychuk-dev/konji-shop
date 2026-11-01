# Product source-description cleanup — 2026-11-01

This repair updates 13 active products: three short descriptions (179, 240, 259) and ten long descriptions (12116–12119, 12157–12160, 13961, 14229). SEO titles/descriptions, names, slugs, variants, pricing, status, publication and categories are read-only. Two contaminated product-level Indeks links are detached, with their raw pivot rows backed up; shared attribute values remain unchanged. The 48 basic SEO descriptions and 61 duplicate-title groups are unaffected.

## Reviewed copy

The short descriptions become the exact SEO descriptions already applied by the colour-correction batch. Products 179/240 use pale pink/light green; 259 stays colour-neutral because its original sources disagree. This removes the three source short-description conflicts without selecting an unconfirmed colour for 259.

Eight Meditrade bodies contain both sterile and non-sterile labels and an invasive-use claim. The manufacturer catalogue identifies references 1137–1140 as sterile four-layer nonwoven compresses packed in pairs for external wound care (printed page 69). Each guarded supplier code and exported default SKU matches its reference. Replacement copy states the individual size and retail quantity from that product's supplied data: one pair or 25 pairs. Existing material percentages and basic packaging precautions are retained. The duplicate supplier prose, invasive-use claim, stock claims, pasted parameter labels and supplier marketing are removed.

Octenilin's manufacturer identifies the 350 ml solution (reference 121701, GTIN 4032651217014) as sterile and gives composition, eight-week opening life and external-use restrictions. Its imported body mixed gel copy, non-sterile parameters, a six-week claim and an allergy-tolerance claim. The replacement is concise solution-specific copy with a direction to follow the manufacturer's complete instructions. It does not add treatment steps or guarantees.

Solventum identifies Scotchcast Plus family construction as fiberglass with water-activated resin. The replacement removes the traditional-gypsum composition and misleading slow-binding/20-minute prose. It does not copy dimensions, colour or carton quantity from the displayed 82001 variant. The existing retail name supplies the single-unit quantity.

Manufacturer links, reference mapping and reviewed replacement HTML are frozen in `review.json`; sources were checked on 2026-11-01. Long bodies are intentionally rewritten, retaining the supported product-identification, material, retail-pack and relevant precaution information described above. They do not preserve the old duplicated supplier HTML or stale stock/shipping statements. These are product descriptions, not replacements for the complete manufacturer instructions supplied with the products.

The same bad parameter text is also embedded in the Indeks attribute values 8815 (Octenilin) and 9701 (Scotchcast). The repair detaches these two values from products 13961 and 14229 only and retains the clean index codes in their new bodies. It does not edit the shared values or remove links from other products. Exact value text, parent attribute 498 and the current pivot rows are checked before deletion. Rollback restores the original pivot rows.

## Data guards and rollback

Both previously applied receipts are pinned: manufacturer-review `65e966fb030abbe992e4484d12d57ea9f28baf4f0a064f37d23908eba3f68298` and colour-correction `e5f06d854dc2d2ba824d3b670869f3988c36121d8f020519f8383a4ca9016e7f`. Their private backup files, checksums and applied receipt states are checked before every mode.

The manifest reconstructs current expected SEO metadata from all four earlier manifests. Source evidence is checked against the complete, untruncated normalized export. A new content-specific repair engine permits only `description` and `short_description`, plus `updated_at`, and the two explicitly reviewed pivot deletions. SEO values are strict read-only guards. Long copy permits only simple paragraph/list/strong tags without attributes; short copy is plain text.

Dry-run is SELECT-only and stores **raw original HTML** and timestamps in a new private plan/backup. Apply locks all targets in stable ID order, re-derives the reviewed operations, compares the complete selected raw rows to the dry-run fingerprint, then updates only the selected content field and timestamp and detaches the two reviewed links in one transaction. A concurrent HTML-formatting edit also blocks apply. Verification compares all selected fields to the original row overlaid with the approved changes, so untouched source fields and SEO metadata must remain identical. Full catalogue metadata coverage and rendered values are also checked.

Rollback validates every target and detached link before restoring any of them and restores the original HTML byte for byte. Later source, identity, SEO or timestamp edits block rollback. The runner preserves the earlier immutable plans and receipts. Since this repair intentionally changes source evidence, earlier full-state verifiers will reject the newer state; verify using this repair, and roll it back first if undoing earlier batches.

The wrapper supports dry-run/apply/verify/rollback with checksum checks before and after copying into the app container. `apply` creates a fresh private directory, runs dry-run, applies, invalidates the catalogue cache and verifies. It does not modify application code in running containers. Tests cover the exact 13 exported source fixtures, read-only preview, raw HTML drift, stale source/SEO guards, immutable write scope, tampered backup rejection, transaction failure atomic rollback refusal and isolation of shared attribute values/other products.
