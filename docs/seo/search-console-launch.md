# Search Console at the ortezka.pl switch

The new shop keeps the same domain (`https://ortezka.pl`, `www` → apex), so this is a site move within one property: **no Change of Address**. Search Console history carries over, and the old URLs are handled by the legacy redirect maps (`docs/seo/legacy-category-redirects-20261101.md`).

## Before the DNS switch

1. **Find the existing property.** `ortezka.pl` already has a `google-site-verification=LpoIfmRn…` TXT record (checked on 2 November 2026), so a Domain property probably exists. Open Search Console with the Google account that owns it. If nobody has access, add a new **Domain** property for `ortezka.pl` and verify it with the TXT record Google shows, in Cloudflare DNS. Keep the existing record either way: deleting it can unverify the current owner.
2. **Add users.** Settings → Users and permissions: give at least one more person Owner access.
3. **Export the old shop's top URLs.** Performance → Search results, last 16 months, Pages tab → Export. The same export from Links → Top linked pages lists external backlinks. Check every URL in both exports against the new server before the switch, and map any that return 404:

   ```bash
   cut -d, -f1 pages.csv | tail -n +2 | while read -r u; do
     curl -sk --resolve ortezka.pl:443:63.183.188.75 -o /dev/null -L --max-redirs 3 \
       -w "%{http_code} %{num_redirects} $u\n" "$u"
   done | sort | uniq -c -w 5
   ```

4. **Leave staging out.** `staging.ortezka.pl` sends `X-Robots-Tag: noindex`; don't add it as a property or submit its sitemap.

## Switch day

1. After DNS points to the new server, confirm `https://ortezka.pl/` returns 200 with no `X-Robots-Tag` header, and that `https://ortezka.pl/robots.txt` lists `Sitemap: https://ortezka.pl/sitemap.xml`.
2. **Sitemaps:** remove `1_index_sitemap.xml` (the old PrestaShop sitemap, now a 404) and submit `https://ortezka.pl/sitemap.xml` (about 15,700 URLs).
3. **URL Inspection → Test live URL** on the homepage, two top categories and two top products from the export. Each should show "URL is available to Google", the user-declared canonical equal to the URL, and Product and Breadcrumb detected on products. Then click **Request indexing** for those pages.
4. Inspect two old URLs from the export as well: the live test should report the redirect to the new page.
5. **Certificate:** make sure the certbot renewal reloads the web container, e.g. a deploy hook running `docker compose -f docker-compose.prod.yml exec web nginx -s reload`. The current certificate expires on 12 December 2026.

## First two weeks

Check every two or three days:

| Report | What to look for | Action |
|---|---|---|
| Indexing → Pages → Not found (404) | Old URLs that had traffic | Add a decision to `scripts/seo/legacy-category-redirects-20261101/manual-products.csv`, rebuild, deploy `web`. A URL that is gone on purpose (89 known products) can stay 404. |
| Pages → Page with redirect | Grows as Google follows the old URLs | Expected; nothing to do. |
| Pages → Duplicate, Google chose different canonical | New URLs | Inspect them; the canonical tags should be self-referencing. |
| Pages → Soft 404 | Empty categories (23 known) | Add products to the category, or merge or remove it. |
| Sitemaps | Status "Success", discovered ≈ submitted | If it reports errors, fetch the sitemap and compare. |
| Settings → Crawl stats | Server errors (5xx) and response time | 5xx: check `docker compose logs web app`. Slow responses: the t3a.small has 2 GB RAM. |
| Enhancements: Product snippets, Merchant listings, Breadcrumbs | Invalid items | Products without a price have no Product markup by design (Vermeiren "Cena niedostępna"); anything else is a bug. |
| Performance | Clicks and impressions against the same weeks before | A dip in the first one or two weeks is normal; a long fall for one page group points to a redirect or content gap. |

After four weeks, compare Performance → Pages with the pre-switch export. Any old top page whose traffic did not carry over to its new URL needs a closer look.

## Optional

Bing Webmaster Tools can import the verified site straight from Search Console (Sign in → Import from GSC). That covers Bing and DuckDuckGo with no separate verification.
