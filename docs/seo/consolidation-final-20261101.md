# Consolidation after complete catalogue and media review

## Correction to the previous identity decision

The complete export and direct review of the manufacturer's colour sections overturn two earlier duplicate classifications. Equal model codes/descriptions do not establish the same colour offer. Both Timago pairs must remain separate. The four existing main photographs were downloaded and visually inspected; their frames/shafts agree with the source colour links. Source HTML and downloaded image hashes are recorded in the manifest.

| Product ID | Manufacturer page | Reviewed offer |
|---|---|---|
| 9983 | https://www.timago.com/pl/podporka-czterokolowa-aluminiowa-ranger.html | RANGER, black frame |
| 9986 | https://www.timago.com/pl/ranger-podporka-czterokolowa-aluminiowa.html | RANGER, white frame |
| 10104 | https://www.timago.com/pl/laska-inwalidzka-aluminiowa-skladana-model-jmc-c-2052.html | JMC-C 2052, black shaft |
| 10107 | https://www.timago.com/pl/laska-inwalidzka-aluminiowa-skladana.html | JMC-C 2052, brown shaft |

The source pages link each colour to its companion. The cane technical sections explicitly label black/brown. The four names, SEO titles and descriptions are differentiated by these colours. Their slugs, prices, variants, statuses and category links remain unchanged. No Timago redirect is added. This decision supersedes the two Timago consolidation recommendations in the immutable earlier identity ledger; its manifest/checksums are not rewritten.

## Three reviewed consolidations

| Archived source | Retained target | Reason for target |
|---|---|---|
| 12619 `vicair-active-o2-42a57ba6` | 12583 `vicair-active-o2` | Model URL and complete dimensions/manual links; full five-image source URL/hash multiset matches. |
| 12623 `vicair-allrounder-02` | 12587 `vicair-allrounder-o2` | Correct O2 model URL; full eleven-image multiset matches. Full HTML already contains measurement instructions and the between-sizes advice missing from the older stripped export. |
| 12626 `vicair-multifunctional-back` | 12590 `vicair-multifunctional-back-o2` | Explicit O2 URL; full five-image multiset matches; retains the training link paragraph absent from its companion. |

The complete export contains 73 gallery rows, no attribute-image rows, no document-table rows and ten draft/unpriced variants. There are no commercial references in the eleven-table inventory. All variant IDs/options/prices/status/stock and all three archived products' original galleries and bodies remain intact. The survivors keep their full existing galleries; no file copy is necessary for the identical Vicair image sets. Three missing category links to category 729 are added as non-primary links, preserving each survivor's existing primary category.

Documents are embedded in the HTML, not `product_documents`. Each source manual/certificate basename has a corresponding link in the survivor. Existing complete target HTML and its document links are retained verbatim. Archived source bodies and their original alternate file URLs are preserved. The two genuinely additional video URLs (`itS-NJZhgXc` for Active O2 and `YcUgijz9nGU` for Allrounder) are appended as labelled manufacturer links; no sizing text is replaced or invented.

## Off-product cane gallery rows

The eight-image gallery on product 10104 includes five images outside its actual product gallery. In the manufacturer's DOM, row 59711 (`produkty/698/17658960765816.jpg`) is the brown companion's colour link under `list__colors`. Rows 59712–59715 are cards under related products: TGR-R LA 833L2, a folding white cane, ELEGANT and ASSIST-TIM. These are not views of the black JMC-C 2052. Keep its genuine three gallery rows 59708–59710 and the brown offer's three existing rows. Remove the five incorrect associations only after recording their entire rows in the private backup. No underlying image file is deleted. A locked check across all products, including morph aliases and soft-deleted records, blocks removal if any product explicitly selects one of those images as its default. Rollback restores the same row IDs, paths, captions and timestamps.

## Guards and operation

Source export SHA-256: `10c743114585ead864452f19385d0dfe996a63c5398bbbbf5d1a433081e6c8d9`. Prerequisite applied identity backup: `storage/app/private/seo-repairs/identity-review-20261101.pOGcEG`, plan SHA-256 `b3fe38efba39fce3ae697aebb3ebe9bf0a8f8cd553dd414613031605977d7ba3`.

The frozen manifest includes the ten complete exported identities/HTML/SEO/default-image pointers, all related catalogue rows and reference counts. The repair reads and records every current product column in its private plan, checks the frozen exported fields/rows, locks affected rows and keys, rejects new commercial/unresolved/schema references and refuses drift before apply. Only the explicit three status changes, four colour metadata/name corrections, two appended video links, three category links and five gallery-row removals are allowed. Plan operations are re-derived and compared before apply/verify/rollback. All database changes commit in one transaction; a missing alias or failed after-state check rolls it back.

The alias JSON extends the previous six pairs to nine, using the unchanged guard/controller. Code installation alone cannot redirect an active product. Runtime patching backs up and checks all three app/queue/scheduler sources, pins the existing guard/controller and legacy nginx-map checksum, and restores alias files on patch failure. All 1,070 nginx rules are untouched. Rollback reactivates the three sources, disables their aliases through the existing status guard and restores exact metadata, bodies, gallery rows, timestamps and category links. Later edits block rollback.

Projected result: active products 14,621 → 14,618; duplicate-title groups 13 → 8. All four Timago offers remain active. No importer, publication, pricing or stock changes are made. Remaining identity/price/configuration blockers are unchanged.

After merging, extract the new script folder, alias JSON and immutable shared engines from the pinned commit. Run `patch-runtime.sh`, then `repair.sh apply`. The wrapper runs a fresh dry run, writes a private checksum-protected plan/backup before changes, applies, invalidates catalogue/navigation caches, verifies the exact state/redirects and checks rendered metadata for every active product. Then run `python3 verify-origin.py` from the extracted script folder on the server. It uses TLS-verified localhost origin requests for staging and apex to check all nine 301 destinations/target 200s/canonicals, staging noindex, all four colour offer pages and sitemap inclusion/exclusion. This avoids confusing public legacy DNS/rate limits with the new origin. Keep the printed backup and receipt paths. `repair.sh verify BACKUP_DIRECTORY` and guarded `repair.sh rollback BACKUP_DIRECTORY` use that same private backup.

Tests exercise full apply/verify/exact rollback, all nine redirects on staging/apex, sitemap source exclusion/target inclusion, retained colour offers, preserved media/variants/manuals/sizing/training/video links, frozen image/variant/body/category drift, new commercial references, arbitrary plan edits, later manual edits and an alias-installation failure with transaction rollback. Python checks pin all nine alias identities, the corrected colour scope, the full Vicair media evidence and the script bundle checksums.
