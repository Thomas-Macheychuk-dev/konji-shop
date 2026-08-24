# Sigvaris production execution

`sigvaris:production-import` is the guarded production write wrapper for the frozen Sigvaris import map.

Approved fingerprints:

- product-data: `6d35626f3013e229e60b03910dc9e5a1807d006ad87f366862f36e4759c76df4`
- combinations: `25f6bdc91f26cd0eb80e1d9b3146e2958ed28817f78e7747c320836c0f176ba0`
- import-map: `7f270865aebbab63c441f82c63d4075451f5c13fdbd49d735f43f00b427635aa`

Frozen catalogue shape:

- 226 draft products
- 14,991 planned variants
- 849 deduplicated images
- 71 category paths
- 208 document links
- 216 products at 8% VAT
- 10 products at 23% VAT
- 1 stable synthetic default variant
- 2 non-blocking source-manufacturer review items

## Safety model

The command is read-only by default. A production write requires:

1. the exact approved import-map SHA-256;
2. an inline production preflight immediately before writes;
3. explicit exact pre-write and post-write global Sigvaris counts;
4. `--confirm-production-write=IMPORT-SIGVARIS-DRAFTS`;
5. at least 500 MiB free-space requirement and at least one source-image probe in production;
6. `--acknowledge-review-items` when the selected set contains a mapped review item.

Every imported product and variant remains draft. The command stops on the first importer warning, image failure, or exception and performs an exact post-write audit against the selected mapping and global counts.

## Production sequence

Run a read-only production preflight first with expected existing counts `0 / 0 / 0`. Then run a one-product smoke write (`--limit=1`) expecting post-write counts matching that mapped product. Audit the result before importing the remaining products. Finally rerun the complete catalogue with unchanged pre/post counts to prove production idempotency.

Do not regenerate `import-map.json` in production. Transfer the exact approved local map and verify its SHA-256 before use.
