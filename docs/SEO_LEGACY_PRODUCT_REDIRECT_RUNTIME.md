# SEO-02E — Disabled-by-default legacy product redirect runtime

SEO-02E converts the human-approved SEO-02D manifest into an Nginx `map` and wires a runtime activation switch into the production web container.

## Safety boundary

The generated redirect map is always present in the web image, but it does nothing by itself. Runtime redirects are disabled unless the production environment explicitly sets:

```dotenv
LEGACY_SEO_REDIRECTS_ENABLED=true
```

The default is `false`.

When disabled, `/etc/nginx/legacy-seo/runtime/10-product-redirects.conf` contains only a comment. When enabled, the startup script installs a server-level `return 301` guard that uses the generated `$uri` map.

The same guard is included in both the HTTP and HTTPS server blocks. This means an approved legacy HTTP URL can redirect directly to the final HTTPS product URL in one hop instead of first redirecting to the HTTPS legacy URL.

Query strings are intentionally not carried to the final target. The Nginx map keys on `$uri`, so legacy query variants of an approved source path resolve to the clean canonical product target.

## Generate the committed map

```bash
php artisan seo:generate-legacy-product-redirect-map \
  --manifest=resources/seo/ortezka/product-redirect-approvals.json \
  --output=docker/nginx/generated/legacy-seo-product-map.conf
```

The generator fails closed on duplicate source paths, source/target identity, target paths outside `/products/`, query/fragment-bearing paths, inactive approved records, count drift, or any target that is also an approved source (redirect-chain/cycle risk).

## Activation gate

Do not set `LEGACY_SEO_REDIRECTS_ENABLED=true` until the built web image has been deployed to staging and all approved targets have been verified to return 200 with a self-referencing canonical and no `noindex` directive.

Enabling or disabling requires recreating/restarting the `web` container so the startup script materializes the appropriate runtime include.

## Supplier production-preflight compatibility

The production web service keeps the public-storage symlink command in `docker-compose.prod.yml` before executing `/usr/local/bin/konji-nginx-start`. ARmedical, Sigvaris, and Zamst production preflights verify that shared-storage exposure contract. The redirect startup script therefore owns only redirect activation plus Nginx validation/startup.
