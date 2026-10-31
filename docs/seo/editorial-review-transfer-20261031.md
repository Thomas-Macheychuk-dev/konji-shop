# Targeted SEO editorial review export

The next editorial pass needs source details for the basic generated descriptions and remaining duplicate-title groups after the database repair. This package reads the current catalogue and its verified private repair backup. It does not update products, cache versions, configuration, redirects, DNS or services.

## Selection and evidence

The exporter reconstructs each generated-description operation without optional attributes. Exact equality with the saved post-repair description identifies the original sparse fallback, including long names that left no room for a generic suffix. Later edited product identities or metadata are reported and excluded from that selection. The original basic count (944 in the applied repair) and the current selected count are reported separately. Current duplicate groups are recomputed from stored titles rather than assumed to remain at 76. Missing metadata introduced since repair is also reported and selected.

Exported evidence includes product names, saved SEO fields, source IDs, plain source descriptions, product attributes, distinct active-variant attribute values, active variant counts, default variant identifiers, category paths and image references. It does not hydrate variant model graphs, expose credentials or read customer/order/payment tables. Descriptions and attribute values have explicit length caps and truncation flags. Categories include current database values and applicable versioned editorial copy.

The data is for review, not automatic publication of source claims. A variant's size or colour does not necessarily describe every variant of its parent product. Conflicting sources and suspected duplicate imports still need identity verification before consolidation or new distinguishing claims.

## Local download

Run `download.py` on the user's computer with Python 3.9+ and their existing authenticated AWS CLI profile. Defaults use the instance/profile/region already supplied for this project. The profile needs `ssm:SendCommand` and `ssm:GetCommandInvocation` for the target and AWS-RunShellScript document. The helper does not change IAM permissions or credentials.

The local package must include this directory and the existing `Metadata.php` and `reviewed-titles.php` from `scripts/seo/product-database-20261031`. It uploads only the four PHP exporter files through SSM, creates a private temporary host/container directory, runs SELECT queries, compresses the report and downloads base64 chunks below SSM's output-size limit. Compressed and uncompressed SHA-256 checksums, byte counts and JSON structure are verified before saving an exclusive local file. Existing output files are not overwritten.

```bash
python3 scripts/seo/editorial-review-20261031/download.py \
  --output konji-seo-editorial-review.json
```

`OUTPUT=` reports an absolute path on the local computer. Attach that JSON for the editorial pass. No nano selection or copying large terminal output is needed. The helper does not create a public download URL or upload catalogue data to a new cloud bucket. Private temporary files remain on the server and container under the reported `/tmp/konji-seo-editorial.*` directory until normal temporary-file cleanup.

The default backup is the completed repair directory `storage/app/private/seo-repairs/product-seo-20261031.LLDLcm`. Override it with `--repair-directory` only for a different applied repair receipt. The PHP entry point requires a checksummed plan and an applied receipt under application private storage. SSM access errors are reported; the helper does not attempt to grant itself broader access.

## Verification

PHP feature tests check SELECT-only execution, sparse-description selection, duplicate identities, later editor changes, active-only variants, de-duplicated option values and category/image evidence. Python tests use fake SSM calls and in-memory chunks to check invocation delays, output limits, corruption, bounded decompression, unsafe paths and overwrite protection. No test uses live AWS credentials or mutates the deployed catalogue.

This change prepares evidence transfer only. It does not claim that the 944 descriptions or 76 groups have already received an editorial correction.
