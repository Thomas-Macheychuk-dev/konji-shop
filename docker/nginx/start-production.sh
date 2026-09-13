#!/bin/sh
set -eu

runtime_dir=/etc/nginx/legacy-seo/runtime
runtime_file="$runtime_dir/10-product-redirects.conf"
available_file=/etc/nginx/legacy-seo/available/10-product-redirects-enabled.conf

mkdir -p "$runtime_dir"

case "${LEGACY_SEO_REDIRECTS_ENABLED:-false}" in
    1|true|TRUE|True|yes|YES|Yes|on|ON|On)
        cp "$available_file" "$runtime_file"
        echo "Legacy SEO product redirects: ENABLED" >&2
        ;;
    0|false|FALSE|False|no|NO|No|off|OFF|Off|"")
        printf '%s\n' '# Legacy SEO product redirects disabled.' > "$runtime_file"
        echo "Legacy SEO product redirects: disabled" >&2
        ;;
    *)
        echo "ERROR: LEGACY_SEO_REDIRECTS_ENABLED must be true/false, 1/0, yes/no, or on/off." >&2
        exit 1
        ;;
esac

nginx -t
exec nginx -g 'daemon off;'
