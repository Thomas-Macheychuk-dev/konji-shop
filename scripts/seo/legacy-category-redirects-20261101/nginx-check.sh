#!/usr/bin/env bash
# Load the committed legacy maps, gates and redirect includes into the production nginx base image,
# then check precedence and responses. Read-only: uses a temporary directory and a throwaway container.
set -euo pipefail

cd "$(dirname "$0")/../../.."
H="$(mktemp -d)"
trap 'rm -r "$H"' EXIT
mkdir -p "$H/conf.d" "$H/available"

# Same names as the Dockerfile installs; conf.d is parsed alphabetically.
cp docker/nginx/map-hash.conf "$H/conf.d/00-hash-tuning.conf"
cp docker/nginx/generated/legacy-seo-product-map.conf "$H/conf.d/00-legacy-seo-product-map.conf"
cp docker/nginx/generated/legacy-seo-staging-extra-map.conf "$H/conf.d/00-legacy-seo-staging-extra-map.conf"
cp docker/nginx/generated/legacy-seo-category-fallback-map.conf "$H/conf.d/02-legacy-seo-category-fallback-map.conf"
cp docker/nginx/legacy-seo/available/*.conf "$H/available/"
cp docker/nginx/verify-seo12-map.sh docker/nginx/verify-legacy-fallback-map.sh "$H/"
sed -n '/^map \$host \$legacy_seo_redirect_origin {/,/^}/p' docker/nginx/production.conf > "$H/conf.d/default.conf"
cat >> "$H/conf.d/default.conf" <<'CONF'
server {
    listen 8080;
    server_name staging.ortezka.pl ortezka.pl www.ortezka.pl;
    include /etc/nginx/legacy-seo/runtime/10-product-redirects.conf;
    include /etc/nginx/legacy-seo/runtime/30-fallback-redirects.conf;
    location / { return 200 "app\n"; }
}
CONF

EXACT="$(grep -m1 -oE '^    "/[^"]+-id-[0-9]+"' docker/nginx/generated/legacy-seo-product-map.conf | tr -d ' "')"
EXACT_TARGET="$(grep -m1 -F "\"$EXACT\"" docker/nginx/generated/legacy-seo-product-map.conf | cut -d'"' -f4)"
FALLBACK="$(grep -m1 -E '^    "/[^"]+-id-[0-9]+" "/categories/' docker/nginx/generated/legacy-seo-category-fallback-map.conf | cut -d'"' -f2)"
FALLBACK_TARGET="$(grep -m1 -F "\"$FALLBACK\"" docker/nginx/generated/legacy-seo-category-fallback-map.conf | cut -d'"' -f4)"

cat > "$H/cases" <<CASES
staging.ortezka.pl $EXACT 301 https://staging.ortezka.pl$EXACT_TARGET
staging.ortezka.pl $FALLBACK 301 https://staging.ortezka.pl$FALLBACK_TARGET
staging.ortezka.pl /ortezy-i-stabilizatory-cat-124 301 https://staging.ortezka.pl/categories/ortezy-i-stabilizatory
staging.ortezka.pl /stetoskopy-pediatryczne-cat-190 301 https://staging.ortezka.pl/categories/sprzet-medyczny
staging.ortezka.pl /na-dzien-babci-i-dziadka-cat-243 410 -
www.ortezka.pl /ortezy-i-stabilizatory-cat-124 301 https://ortezka.pl/categories/ortezy-i-stabilizatory
ortezka.pl $FALLBACK 301 https://ortezka.pl$FALLBACK_TARGET
staging.ortezka.pl /products/vicair-active-o2 200 -
staging.ortezka.pl /ortezy-i-stabilizatory-cat-124/extra 200 -
CASES

docker run --rm -v "$H:/h:ro" nginx:1.27-alpine sh -c '
set -eu
mkdir -p /etc/nginx/legacy-seo/runtime /etc/nginx/legacy-seo/available
cp /h/conf.d/* /etc/nginx/conf.d/
cp /h/available/* /etc/nginx/legacy-seo/available/
sh /h/verify-seo12-map.sh
sh /h/verify-legacy-fallback-map.sh
cp /etc/nginx/legacy-seo/available/10-product-redirects-enabled.conf /etc/nginx/legacy-seo/runtime/10-product-redirects.conf
cp /etc/nginx/legacy-seo/available/30-fallback-redirects-enabled.conf /etc/nginx/legacy-seo/runtime/30-fallback-redirects.conf
nginx -t
nginx
failed=0
while read -r host path status location; do
    # Raw HTTP, because busybox wget always follows redirects.
    out="$(printf "GET %s HTTP/1.0\r\nHost: %s\r\n\r\n" "$path" "$host" | nc 127.0.0.1 8080 | tr -d "\r")"
    got_status="$(printf "%s\n" "$out" | sed -n "1s#^HTTP/[0-9.]* \([0-9]*\).*#\1#p")"
    got_location="$(printf "%s\n" "$out" | sed -n "s#^Location: ##p" | head -1)"
    [ "$location" = "-" ] && location=""
    if [ "$got_status" = "$status" ] && [ "$got_location" = "$location" ]; then
        echo "PASS $status $host$path"
    else
        echo "FAIL $host$path expected $status $location got $got_status $got_location"
        failed=1
    fi
done < /h/cases
nginx -s stop
[ "$failed" = 0 ] && echo "LEGACY_FALLBACK_NGINX_CHECK=PASS"
exit "$failed"
'
