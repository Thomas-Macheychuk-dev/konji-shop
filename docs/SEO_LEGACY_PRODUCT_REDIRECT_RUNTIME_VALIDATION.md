# SEO-02G — Legacy product redirect runtime validation

SEO-02G is the staging-only runtime gate for the first human-approved Ortezka product redirect cohort.

It does **not** enable or disable redirects. Activation remains an explicit environment/deployment action through `LEGACY_SEO_REDIRECTS_ENABLED` on the Nginx web container.

## Command

```bash
php artisan seo:validate-legacy-product-redirect-runtime \
  --base-url=https://staging.ortezka.pl
```

Default inputs:

- approval manifest: `resources/seo/ortezka/product-redirect-approvals.json`
- evidence: `storage/app/seo/ortezka/legacy-product-redirect-runtime-validation.json`
- unrelated control path: `/robots.txt`

## What is validated

For every approved legacy source path the command requests the source with a synthetic query string while redirects are disabled in the HTTP client. It requires:

1. source response is exactly HTTP `301`;
2. `Location` is exactly the approved final `/products/{slug}` URL;
3. the synthetic query string is not present in `Location`;
4. the final target is requested directly and returns HTTP `200`, proving there is no redirect chain;
5. the final page has exactly one self-referencing canonical;
6. the final page is indexable (`noindex` absent from HTML and `X-Robots-Tag`);
7. the final page H1 matches the approved target product identity.

The validator also detects redirect loops and requests `/robots.txt` as an unrelated control URL. The control must remain a direct HTTP `200` with no `Location` header.

## Human traffic protection

When traffic protection is enabled, SEO-02G uses the same existing signed/encrypted human-verification cookie mechanism as SEO-02F. The cookie is sent only when `--base-url` has the same host as configured `APP_URL`. Its value is never written to evidence.

## Expected first-cohort result

```text
Approved legacy source paths:   36

Source HTTP 301:                 36
Correct destinations:            36
Query strings dropped:           36
Final target HTTP 200:            36
Canonical correct:                36
Indexable:                        36
Product identity correct:         36

Wrong destinations:               0
Missing Location headers:         0
Redirect chains:                  0
Redirect loops:                   0
Query strings preserved:          0
Source request failures:          0
Target request failures:          0
Other source statuses:            0
Other target statuses:            0
Canonical mismatches:             0
Noindex targets:                  0
Identity mismatches:               0
Unrelated control failures:       0
Duplicate/conflicting sources:    0

Human verification cookie used: YES
Redirect activation changed by this command: NO
RESULT: PASS
```

## Operational gate

SEO-02F must already pass with redirects disabled before testing SEO-02G.

Then, on staging only:

1. set `LEGACY_SEO_REDIRECTS_ENABLED=true` for the **web** container;
2. recreate/restart the web container so the startup script materializes the active runtime include;
3. run `nginx -t`;
4. run SEO-02G from the app container against the staging hostname;
5. preserve the generated evidence;
6. if any assertion fails, set the flag back to `false`, recreate the web container, and investigate;
7. even a full SEO-02G PASS is evidence for a later production-activation decision; the command itself never authorizes or performs production activation.
