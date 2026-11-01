# SEO colour correction — 2026-11-01

The manufacturer-review repair applied successfully with plan hash `65e966fb030abbe992e4484d12d57ea9f28baf4f0a064f37d23908eba3f68298`, backup `storage/app/private/seo-repairs/manufacturer-review-20261101.PJiAHp`, 30 verified products and 14,627 rendered metadata matches.

Its sample exposed an editorial oversight: product 179 has a pale-pink title and body, but its preserved SEO description says light blue. Review of all 35 title changes across the three batches found one additional explicit colour conflict: product 240 has a light-green title/body but a pink SEO description. The other changed titles' SEO descriptions contain no explicit conflicting colour or dimensions in the exported evidence.

This two-product correction rewrites only those SEO descriptions from their own bodies. Titles, identifiers, slugs, product descriptions, variants, prices and categories do not change. Duplicate-title groups remain 61; the 48 basic-description holds are unaffected.

Both imported short descriptions also contain the incorrect colours. Their source cleanup is tracked separately: this metadata-only repair does not change them. The earlier ten long-description conflicts also remain outstanding.

The manifest expects the **applied manufacturer-review title and description**, rather than stale original-export metadata. Source description hashes remain pinned. The shared engine provides transactional source/metadata guards, collision checks, a fresh immutable plan/backup, cache invalidation, verification and guarded rollback.

The preceding applied receipt is checked for private location, checksum and applied state before every mode. Current target values are checked by the new repair manifest/plan. The older full-state verifier is not run after superseding its two values: it would correctly reject this newer state. Do not rerun or roll back the manufacturer batch while this correction is applied. To undo both, roll back this correction first.

CI covers exact exported source fixtures, correction of both colours, unchanged titles and source fields, prior receipt validity after supersession, guarded receipt rejection and rollback. Deployment uses `scripts/seo/colour-correction-20261101/repair.sh apply` from the pinned reviewed commit. It stages the original shared engine and Metadata.php plus the new correction folder, verifies six runtime checksums, runs dry-run, apply and full rendered-metadata verification, and prints a new private backup directory.
