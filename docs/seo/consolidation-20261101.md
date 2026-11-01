# Six reviewed product consolidations — 2026-11-01

This is a reversible catalogue consolidation, not another title differentiation batch. Six duplicate Vermeiren products become archived and their public URLs return same-host 301 redirects to the surviving active product. Expected active products: 14,621 (from 14,627). Expected active duplicate-title groups: 26 (from 32). Six missing category memberships are added to the survivors as non-primary links. No rows are deleted, moved or reassigned. All source variants, metadata, images, documents and category links remain on the archived record. Existing primary categories stay unchanged. Prices, stock, publication dates and variant statuses are untouched.

| Archive source | Surviving product | Model |
|---|---|---|
| 12427, v500-light-321fc813 | 12424, v500-light | V500 Light |
| 12617, vicair-liberty-e00d9c3c | 12581, vicair-liberty | Vicair Liberty |
| 12618, vicair-liberty-profile-4b912b06 | 12582, vicair-liberty-profile | Vicair Liberty Profile |
| 12620, vicair-vector-02 | 12584, vicair-vector-o2 | Vicair Vector O2 |
| 12621, vicair-adjuster-o2-87cb2898 | 12585, vicair-adjuster-o2 | Vicair Adjuster O2 |
| 12622, vicair-multifunctional-o2-b604a48b | 12586, vicair-multifunctional-o2 | Vicair Multifunctional O2 |

The survivors retain the short established model slugs and the functional product categories. V500 Light also gains the source's active-wheelchair category; the five cushions gain the Vicair membership. The source primary category is not copied as a second primary. Existing legacy nginx mappings and legacy placeholder routes do not target these six source slugs; none of the 1,070 nginx rules change.

## Evidence and live gates

The fresh reference export shows identical full normalized descriptions and identical product attribute values for each pair, one unpriced draft variant per record, and no order/cart/withdrawal references for any of the 66 reviewed records. The original targeted export also contains matching manufacturer image URLs for these six pairs. That original export sampled at most three images: it does not prove the full current galleries match. The runner therefore requires the complete live image and attribute-image URL multisets to match within each pair, and requires all reviewed manufacturer image samples to remain present. Empty/unknown image identities block apply. This excludes the more ambiguous PEPI/Active/Allrounder/backrest pairs for now.

The manufacturer's V500 Light documentation identifies the model; no new medical specification or treatment claim is introduced. Reference: https://www.vermeiren.pl/product/brochure.nsf/O/15B567DD85437DC3C1258B4900404601/%24FILE/V500%20Light%20-%20PL.pdf . Consolidation evidence is primarily the own frozen body/attribute/media identity and a live comparison, rather than a name or SKU suffix alone.

The preceding applied repair is pinned to `storage/app/private/seo-repairs/variant-review-20261101.NmgeeB`, SHA-256 `604ca4387852727450f392ffb34eb4a0afed5bb77cec02991977e0b19ffe07d6`. Exact current names/slugs/source IDs/SEO values/body hashes, attribute labels/values and memberships must still match. The runner reads the current reference schema, rejects new/unresolved reference types and blocks any commercial/unreviewed reference. During apply it locks product, variant, category, media/attribute and reference rows before rechecking its complete private snapshot. Raw source fields and catalogue relation rows are backed up. Counts never authorize a merge by themselves.

Existing row IDs are checked exactly. Only database-generated IDs on the six newly inserted pivots are excluded from the predicted after-state comparison; every membership value and timestamp remains pinned. The immutable dry-run plan records full before state, the six archive operations and six category insertions. Apply writes only source status/updated_at and those new non-primary pivots. It verifies the expected complete after state inside the transaction and invokes the real product controller for all six 301 responses. If runtime redirect support is missing, the transaction rolls back. A receipt supports post-commit recovery. Catalogue and navigation caches are invalidated and active rendered metadata is verified.

Rollback compares the entire affected state before touching anything. A later source, target, relation or commercial-reference edit blocks rollback. It removes only the six newly inserted target pivots and restores each original source status/timestamp, then checks the exact original snapshot. The installed redirect helper becomes inactive when sources return to active. No media/order/cart/reference migration is needed because every original ID and row is retained.

## Runtime and deployment

`ReviewedProductAliases` acts only when the exact reviewed source identity is archived and its exact reviewed target identity remains active/non-deleted. The controller checks it before normal inactive-product handling. Missing/changed/inactive targets never receive a redirect. Same-host URLs preserve staging/production separation. Installing the code alone activates no aliases. Existing inactive-product admin preview behavior remains for unrelated products; the dedicated admin edit route remains available for archived aliases.

`patch-runtime.sh` validates the reviewed old/new source hashes in app, queue and scheduler before writes and checks the unchanged approved nginx map hash. It stages and syntax-checks all files, backs up existing files or absence markers, installs support files before the controller, checks all hashes and reloads app OPcache. Failure triggers source rollback. It makes no database changes. `repair.sh apply` then creates its private dry-run backup, applies and verifies the data consolidation. Keep both printed backup paths.

After merging, extract the pinned commit's three app/resource files, this script folder and the shared editorial repair, colour receipt checker, variant reference audit and Metadata.php. Run `patch-runtime.sh` first, then `repair.sh apply`. For rollback, run `repair.sh rollback PRINTED_PRIVATE_DATA_BACKUP` before reverting runtime source files.

The remaining 26 groups stay explicitly held. In particular, the two protect.Ankle offers have equal options but different prices (56 PLN versus 200 PLN in the export), so this batch does not choose a selling price or archive either offer. The 29 earlier basic-description holds remain unresolved. Importer deduplication is deferred as requested; new imports should be checked before later consolidation batches.
