# ortezka.pl cutover runbook

Moving `ortezka.pl` from the old PrestaShop shop to the Konji storefront that runs at `staging.ortezka.pl`.
The same EC2 origin (63.183.188.75) already serves both hostnames. See
[SEO_PRODUCTION_HOST_CUTOVER_READINESS.md](SEO_PRODUCTION_HOST_CUTOVER_READINESS.md) for how the vhosts are set up.
The switch itself is a DNS change in Cloudflare; nothing needs deploying.

This is not a domain move: the domain stays `ortezka.pl`, so there is **no Change of Address** in Search Console.
Old URLs reach the new pages through the legacy redirect maps (`docker/nginx/generated/`).

Run server commands from `/var/www/konji-shop`. Always use `-f docker-compose.prod.yml`, because plain `docker compose` hits a stray dev stack.

## 1. Before the switch

### 1.1 Sign-off

- [ ] The exact product map (`legacy-seo-product-map.conf`) still carries the header "SEO-12 RECOVERY CANDIDATE – NOT YET AUTHORIZED FOR DEPLOYMENT". It is already live on staging. Whoever owns that approval signs it off. Once DNS moves, the map serves real ortezka.pl traffic.

### 1.2 Redirects are on, and the staging-only map is off

```bash
docker compose -f docker-compose.prod.yml logs web 2>&1 | grep -E 'Legacy SEO .*redirects: (ENABLED|disabled)' | tail -3
```

Expected output:
- `Legacy SEO product redirects: ENABLED`
- `Legacy SEO category and product fallback redirects: ENABLED`
- `Legacy SEO staging candidate redirects: disabled`

The staging candidate map is only included in the staging vhost, but keep it off anyway.

### 1.3 Application settings that depend on the hostname

```bash
docker compose -f docker-compose.prod.yml exec app php artisan tinker --execute='dump(["app.url" => config("app.url"), "paynow.sandbox" => config("payments.providers.paynow.sandbox"), "traffic_protection" => config("traffic_protection.enabled"), "turnstile_hosts" => config("traffic_protection.turnstile.allowed_hostnames"), "mail_from" => config("mail.from.address"), "session_domain" => config("session.domain")]);'
docker compose -f docker-compose.prod.yml exec app php artisan shop:check
```

- [ ] `APP_URL=https://ortezka.pl`. Queued mail, feeds and anything generated outside a web request use it. Web pages, canonicals and the sitemap follow the request host, so they're already correct.
- [ ] `TURNSTILE_ALLOWED_HOSTNAMES` includes `ortezka.pl` (and `staging.ortezka.pl` if staging stays reachable). It defaults to the `APP_URL` host. Add `ortezka.pl` to the allowed domains of the Turnstile widget in Cloudflare.
- [ ] `SESSION_DOMAIN` is empty or `ortezka.pl`, never `staging.ortezka.pl`.
- [ ] Paynow: production keys set and `PAYNOW_SANDBOX=false`. In the Paynow merchant panel, the notification URL is `https://ortezka.pl/payments/paynow/notifications`.
- [ ] `MAIL_FROM_ADDRESS` is on the shop domain.
- [ ] After changing `.env`: `docker compose -f docker-compose.prod.yml up -d` (restarts app, queue and scheduler), then `php artisan config:cache` if the deploy script normally does that.

### 1.4 What ortezka.pl will serve

Send ortezka.pl requests straight to the new origin, before DNS changes:

```bash
scripts/seo/cutover-preflight.sh 63.183.188.75
```

It must end with `CUTOVER_PREFLIGHT=PASS`. It checks:
- the certificate (ortezka.pl and www) and host canonicalisation;
- one-hop redirects for the old URLs with the most Search Console traffic;
- 404s for old search and sitemap URLs;
- no `noindex`, canonicals on ortezka.pl, Product structured data;
- robots.txt and the sitemap.

### 1.5 Certificate renewal

The ortezka.pl certificate expires on **12 Dec 2026**.

```bash
sudo grep -E 'authenticator|webroot_path' /etc/letsencrypt/renewal/ortezka.pl.conf
```

- `authenticator = webroot`: renewal works once DNS points here. Run the dry run in step 3.
- Anything else (DNS plugin, manual): note how it renews and check the dry run anyway.

The ortezka.pl HTTPS vhost has no `/.well-known/acme-challenge/` location; only the port-80 vhost does. So if Cloudflare's "Always Use HTTPS" sends the challenge to port 443, HTTP-01 renewal fails. In that case, add a Cloudflare configuration rule that skips HTTPS redirection for `/.well-known/acme-challenge/*`, or add the same `location` block to the 443 vhost.

### 1.6 Cloudflare

- [ ] SSL/TLS mode **Full (strict)**. The origin has a valid certificate.
- [ ] Review rules left over from the old shop: page rules, redirect rules, cache rules, rate limits.
  - Before the switch, `curl` to ortezka.pl got `429` from Cloudflare.
  - Rate limiting and Bot Fight Mode must not challenge verified bots (Googlebot, Storebot-Google, Google-InspectionTool).
  - Remove any redirect rule that points at old PrestaShop paths. nginx handles those now.
- [ ] Cloudflare's managed robots.txt (AI-crawler blocks) is fine to keep. It's prepended to the app's robots.txt.

### 1.7 Old shop

- [ ] Leave the old server running and untouched for at least two weeks. Its IP is the rollback target.
- [ ] Pause new orders on the old shop, or export them, around the switch (outside SEO, but it's the same moment).

## 2. The switch

1. In Cloudflare DNS, point `ortezka.pl` and `www.ortezka.pl` at `63.183.188.75`, proxied (orange cloud).
   - Note down the old records first.
   - Remove `AAAA` records that point at the old origin.
2. Purge the Cloudflare cache (Caching → Purge Everything), so old PrestaShop pages and assets aren't served from the edge.

## 3. Right after the switch (first hour)

```bash
dig +short ortezka.pl          # Cloudflare addresses; the origin itself is checked by the next command
scripts/seo/cutover-preflight.sh   # same checks, now through public DNS and Cloudflare
sudo certbot renew --dry-run --cert-name ortezka.pl
docker compose -f docker-compose.prod.yml logs --since=30m app | grep -E 'Traffic protection|spoofed_or_unverified|ERROR' | tail -20
```

- [ ] Preflight ends with `CUTOVER_PREFLIGHT=PASS`.
- [ ] Certbot dry run succeeds.
- [ ] No `spoofed_or_unverified_google` blocks for real Googlebot traffic.
  - The bot gate verifies Googlebot by IP. It needs the real visitor IP from `CF-Connecting-IP`, which `docker/nginx/cloudflare-real-ip.conf` restores.
  - If real Googlebot is blocked, check that Cloudflare still sends `CF-Connecting-IP` and that the range list is current.
- [ ] Test an order end to end on ortezka.pl: cart, checkout, Paynow, confirmation email, admin order view.

## 4. Search Console (same day)

Use property `sc-domain:ortezka.pl`.

1. **Sitemaps:**
   - Submit `https://ortezka.pl/sitemap.xml`.
   - Remove the old entries: `https://ortezka.pl/1_index_sitemap.xml`, `https://ortezka.pl/1_pl_0_sitemap.xml`, `https://www.ortezka.pl/1_index_sitemap.xml`, `https://www.ortezka.pl/1_pl_0_sitemap.xml`, `https://www.ortezka.pl/sitemap.xml`, and the `https://www.ortezka.pl/content/17-wkladki-ortopedyczne-na-zamowienie` entry.
2. **URL Inspection → Test live URL** on the pages with the most clicks, then request indexing:
   - `https://ortezka.pl/`
   - `/categories/turbany-i-chusty`
   - `/categories/kregoslup-i-tulow`
   - `/products/rekaw-ochronny-pod-orteze-am-rb`
   - `/contact`
   - Also inspect two old URLs, e.g. `/korektory-postawy-cat-237`. The live test should show the redirect.
3. **No Change of Address.** The domain doesn't change.

## 5. First two weeks

Check every few days:

- **Page indexing:**
  - "Not found (404)" should only grow with URLs nobody links to any more.
  - "Redirect error" should stay at zero.
  - "Page with redirect" will grow. That's the old URLs, and it's expected.
- **Soft 404:** old products the new shop doesn't sell (about 520 with traffic) redirect to a category page. Google may report some as soft 404. That's expected; they had no product page to keep.
- **Performance:** compare clicks with the weeks before the switch (about 4,700 clicks over 30 Sep – 4 Nov).
  - A dip in the first 1–2 weeks is normal while Google recrawls.
  - After a duplicate fix, Google can take up to two weeks to settle on new canonical URLs.
- **Shopping:** the Product snippets and Merchant listings reports should fill again with `/products/…` URLs.

Keep the legacy redirect maps for at least a year.

## Rollback

If the new shop can't take orders, point the Cloudflare DNS records back at the old origin and purge the cache. Nothing else on the new server needs undoing.
