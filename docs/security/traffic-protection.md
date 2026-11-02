# Storefront traffic protection

This layer is intended to allow normal human browser traffic and verified search engine crawling (Google, Bing, DuckDuckGo, Apple) while rejecting known automated crawlers and command-line clients.

## Runtime flow

1. `/up`, `/robots.txt`, `/human-check` and Paynow notifications are exempt from the human challenge.
2. A request whose User-Agent matches a crawler in `traffic_protection.verified_crawlers` is allowed only when its source IP matches that crawler's published CIDR ranges. If that crawler's ranges file has never been downloaded, the request is let through and a warning is logged, so a fresh install cannot lock search engines out.
3. Known crawler, scraper, headless and command-line User-Agents are rejected with HTTP 403.
4. A normal browser without a valid `konji_human_verified` cookie is redirected to `/human-check`.
5. `/human-check` validates Cloudflare Turnstile server-side. A successful validation issues a signed, HttpOnly human-verification cookie.
6. Non-GET requests without prior human verification are rejected with HTTP 403.
7. Production Nginx applies per-IP request throttles before PHP while leaving health and Paynow notification endpoints unthrottled.

Google Analytics browser measurement does not require an inbound crawler exemption; the browser sends analytics traffic to Google after the human visitor is allowed through.

## Production activation

Do not enable the middleware until Turnstile is configured and the crawler ranges have been downloaded. The production deploy script refreshes them on every deploy.

Set production environment values:

```dotenv
TRAFFIC_PROTECTION_ENABLED=false
TURNSTILE_SITE_KEY=<public site key>
TURNSTILE_SECRET_KEY=<secret key>
TURNSTILE_ACTION=human-traffic
TURNSTILE_ALLOWED_HOSTNAMES=konji.example,www.konji.example
```

Refresh the crawler ranges inside the application container (pass names such as `google bing` to refresh only some):

```bash
php artisan traffic:refresh-crawler-ranges
```

Confirm each file exists and contains prefixes:

```bash
for c in google bing duckduckgo apple; do php -r '$p="storage/app/security/'$c'-crawler-ranges.json"; $d=json_decode(file_get_contents($p), true); echo "'$c' ".count($d["prefixes"] ?? []).PHP_EOL;'; done
```

Facebook's link-preview crawler (`facebookexternalhit`) stays blocked: Meta publishes no IP range feed, so Facebook, Messenger and WhatsApp links show no preview while protection is on.

Then set:

```dotenv
TRAFFIC_PROTECTION_ENABLED=true
```

and clear cached configuration:

```bash
php artisan optimize:clear
```

The scheduler refreshes all crawler ranges every day at 03:15.

## Required exclusions

`POST /payments/paynow/notifications` is a trusted machine-to-machine endpoint and must not be placed behind Turnstile. `/up` must remain available for infrastructure health checks.

When new webhook/callback endpoints are added, explicitly assess whether they need to be added to `traffic_protection.exempt_paths` and to the Nginx rate-limit exemptions.

## Reverse proxy warning

The supplied production Nginx configuration overwrites `X-Forwarded-For` with Nginx's observed `$remote_addr`. This prevents clients from spoofing a Google source IP while the EC2 host is internet-facing directly.

If the service is later moved behind Cloudflare, an ALB, or another trusted reverse proxy, configure Nginx `real_ip` handling for that proxy before enabling Google IP verification. Otherwise Laravel will see the proxy's address rather than the original visitor address.

## Rollback

Set `TRAFFIC_PROTECTION_ENABLED=false` and clear config. Nginx rate limiting remains independent; revert the Nginx production config if that layer also needs to be disabled.
