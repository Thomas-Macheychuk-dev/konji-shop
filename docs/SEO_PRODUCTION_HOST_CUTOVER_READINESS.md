# SEO-02H — Production host cutover readiness

SEO-02H prepares the Nginx web image for `ortezka.pl` without changing DNS or enabling legacy redirects.

## Host roles

- `staging.ortezka.pl` remains an independent HTTPS storefront using its existing certificate.
- `ortezka.pl` serves the Konji storefront using `/etc/letsencrypt/live/ortezka.pl/`.
- `www.ortezka.pl` canonicalizes to `https://ortezka.pl`.
- Approved legacy redirects remain controlled only by `LEGACY_SEO_REDIRECTS_ENABLED`.

When legacy redirects are enabled, the redirect origin is host-aware:

- staging source -> staging product target;
- apex source -> apex product target;
- www source -> apex product target directly.

This avoids a `www old -> apex old -> apex product` redirect chain.

## Cloudflare

The web image restores the original visitor address from `CF-Connecting-IP` only when the request source is within Cloudflare's published proxy networks. This keeps Nginx IP rate limiting per visitor after the production DNS records remain proxied through Cloudflare.

The Cloudflare ranges in `docker/nginx/cloudflare-real-ip.conf` must be reconciled against Cloudflare's authoritative published ranges during infrastructure maintenance.

## Activation boundary

This patch does not:

- change public DNS;
- change `APP_URL`;
- add Paynow production credentials;
- enable legacy redirects;
- authorize production cutover.

Before DNS cutover, validate the production certificate, Cloudflare SSL mode, production application environment, Paynow credentials/callbacks, and the production vhosts directly against the EC2 origin.
