# SEO-02F — Approved legacy product target validation

SEO-02F is the pre-activation gate for the first human-approved Ortezka product redirect cohort.

It validates the final Konji storefront targets from:

```text
resources/seo/ortezka/product-redirect-approvals.json
```

It does **not** enable redirects and it does not mutate catalogue data.

## Command

Run against the production-style staging storefront after the current SEO-02B → SEO-02E/R1 work has been committed and deployed with redirects still disabled:

```bash
php artisan seo:validate-approved-product-targets \
  --base-url=https://staging.ortezka.pl
```

The base URL is deliberately mandatory. The validator never silently falls back to `APP_URL`, which avoids accidentally validating the wrong host during domain cutover work.

By default, JSON evidence is written to:

```text
storage/app/seo/ortezka/approved-product-target-validation.json
```

A different repository-relative evidence path can be supplied with `--output=`.

## Required gate

For every approved target, the validator performs a GET with redirect following disabled and requires:

- HTTP 200 exactly;
- no redirect at the target URL;
- exactly one canonical link that resolves back to the requested target URL;
- no `noindex` directive in `meta[name=robots]`, `meta[name=googlebot]`, or `X-Robots-Tag`;
- the first `<h1>` to equal the approved `target_product_name` after whitespace/case normalization;
- unique target paths and unique legacy source paths in the approval manifest.

The current first cohort is expected to produce:

```text
Approved target products:       23

HTTP 200:                       23
Canonical correct:              23
Indexable:                      23
Product identity correct:       23

Missing targets:                 0
Redirected targets:              0
Canonical mismatches:            0
Noindex targets:                 0
Identity mismatches:              0
Request failures:                 0
Other HTTP failures:              0
Duplicate/conflicting targets:    0

RESULT: PASS
```

Any non-zero failure count makes the command exit non-zero.

## Safety rule

A passing SEO-02F run authorizes only the next validation stage. It does **not** authorize production redirect activation.

The next sequence remains:

1. deploy/build the web runtime with `LEGACY_SEO_REDIRECTS_ENABLED=false`;
2. run `nginx -t` in the actual production-style image;
3. temporarily enable redirects in staging only;
4. test all 36 approved legacy source paths end-to-end;
5. require one 301 only, the approved final target, HTTP 200, self-canonical, indexable, no loops/chains, and query-string removal;
6. verify unrelated URLs are unchanged;
7. only then consider production activation.
