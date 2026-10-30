#!/usr/bin/env bash
set -euo pipefail
artifact_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$artifact_root"
app_container=konji-shop-prod-app-1
web_container=konji-shop-prod-web-1
stage=/tmp/konji-seo-audit-20261030.php
docker cp scripts/seo/audit-storefront-20261030.php "$app_container:$stage"
docker exec -w /var/www/html "$app_container" php "$stage"
# Inspect the active runtime map without installing, regenerating or reloading it.
docker exec "$web_container" sh -c '
map=/etc/nginx/conf.d/00-legacy-seo-product-map.conf
sha256sum "$map"
awk '\''/^    "/ { count++ } END { print "RUNTIME_MAP_RULES=" count+0 }'\'' "$map"
if [ -f /etc/nginx/legacy-seo/runtime/10-product-redirects.conf ]; then
    echo LEGACY_RUNTIME_INCLUDE=present
    cat /etc/nginx/legacy-seo/runtime/10-product-redirects.conf
else
    echo LEGACY_RUNTIME_INCLUDE=absent
fi
'
# Origin-only checks use explicit SNI/Host and preserve TLS verification.
# They do not modify public DNS, the application, or any redirect map.
for audit_host in staging.ortezka.pl ortezka.pl; do
    for audit_path in / /robots.txt /sitemap.xml; do
        echo "ORIGIN_URL=https://$audit_host$audit_path"
        curl --silent --show-error --max-time 30 \
            --resolve "$audit_host:443:127.0.0.1" \
            --dump-header - --output /dev/null \
            "https://$audit_host$audit_path" |
            awk '/^HTTP\// || tolower($0) ~ /^(content-type|x-robots-tag|location|cache-control):/ { sub(/\r$/, ""); print }'
    done
done
echo 'DATABASE_WRITES=0'
echo 'RUNTIME_MUTATIONS=0'
echo 'SEO_AUDIT=COMPLETE'
