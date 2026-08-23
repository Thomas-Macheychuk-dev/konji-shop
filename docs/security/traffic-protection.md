# Storefront traffic protection

This layer is intended to allow normal human browser traffic and verified Google crawling/fetching while rejecting known automated crawlers and command-line clients.

## Runtime flow

1. `/up`, `/robots.txt`, `/human-check` and Paynow notifications are exempt from the human challenge.
2. A request claiming to be a Google crawler/fetcher is allowed only when its source IP matches Google's published crawler/fetcher CIDR ranges.
3. Known crawler, scraper, headless and command-line User-Agents are rejected with HTTP 403.
4. A normal browser without a valid `konji_human_verified` cookie is redirected to `/human-check`.
5. `/human-check` validates Cloudflare Turnstile server-side. A successful validation issues a signed, HttpOnly human-verification cookie.
6. Non-GET requests without prior human verification are rejected with HTTP 403.
7. Production Nginx applies per-IP request throttles before PHP while leaving health and Paynow notification endpoints unthrottled.

Google Analytics browser measurement does not require an inbound crawler exemption; the browser sends analytics traffic to Google after the human visitor is allowed through.

## Production activation

Do not enable the middleware until Turnstile is configured and Google's ranges have been downloaded.

Set production environment values:

```dotenv
TRAFFIC_PROTECTION_ENABLED=false
TURNSTILE_SITE_KEY=<public site key>
TURNSTILE_SECRET_KEY=<secret key>
TURNSTILE_ACTION=human-traffic
TURNSTILE_ALLOWED_HOSTNAMES=konji.example,www.konji.example
```

Refresh Google ranges inside the application container:

```bash
php artisan traffic:refresh-google-crawler-ranges
```

Confirm the file exists and contains prefixes:

```bash
php -r '$p="storage/app/security/google-crawler-ranges.json"; $d=json_decode(file_get_contents($p), true); echo count($d["prefixes"] ?? []).PHP_EOL;'
```

Then set:

```dotenv
TRAFFIC_PROTECTION_ENABLED=true
```

and clear cached configuration:

```bash
php artisan optimize:clear
```

The scheduler refreshes Google's ranges every day at 03:15.

## Required exclusions

`POST /payments/paynow/notifications` is a trusted machine-to-machine endpoint and must not be placed behind Turnstile. `/up` must remain available for infrastructure health checks.

When new webhook/callback endpoints are added, explicitly assess whether they need to be added to `traffic_protection.exempt_paths` and to the Nginx rate-limit exemptions.

## Reverse proxy warning

The supplied production Nginx configuration overwrites `X-Forwarded-For` with Nginx's observed `$remote_addr`. This prevents clients from spoofing a Google source IP while the EC2 host is internet-facing directly.

If the service is later moved behind Cloudflare, an ALB, or another trusted reverse proxy, configure Nginx `real_ip` handling for that proxy before enabling Google IP verification. Otherwise Laravel will see the proxy's address rather than the original visitor address.

## Rollback

Set `TRAFFIC_PROTECTION_ENABLED=false` and clear config. Nginx rate limiting remains independent; revert the Nginx production config if that layer also needs to be disabled.
