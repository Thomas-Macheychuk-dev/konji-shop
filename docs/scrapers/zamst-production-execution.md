# Zamst production execution

The production execution command is `zamst:production-import`.

It is dry-run only unless `--write` is supplied. Production writes also require:

- the exact approved import-map SHA-256 via `--expected-sha256`;
- exact expected existing Zamst product, variant, and image counts;
- exact expected post-write Zamst product, variant, and image counts;
- `--confirm-production-write=IMPORT-ZAMST-DRAFTS`;
- `--allow-unverified-vat` while the approved Zamst map still contains VAT review fallbacks.

The command runs `ZamstProductionPreflight` inline immediately before any write. It does not accept a saved preflight report as authorization.

All imported products remain `draft` with `published_at = NULL`. The command always imports the complete mapped image set for each selected product.

## Approved catalogue fingerprint

Use the SHA emitted by the validated readiness preflight. Do not regenerate the import map between approval and production execution unless the catalogue is deliberately re-reviewed and a new fingerprint is approved.

## Required sequence

1. Run the SHA-pinned production dry-run with expected existing counts of zero.
2. Run a one-product smoke import with `--limit=1` and expected post totals for that complete product.
3. Audit the smoke product in the production admin/storefront while it remains draft.
4. Run the full 24-product import with expected existing totals matching the smoke state and post totals `24 / 108 / 294`.
5. Run the same full command again with existing and post totals both `24 / 108 / 294` to prove production idempotency.
6. Do not publish Zamst products until VAT review and visual/content review are complete.

Optional `--save-preflight` and `--save-audit` paths persist JSON evidence under `storage/app`.
