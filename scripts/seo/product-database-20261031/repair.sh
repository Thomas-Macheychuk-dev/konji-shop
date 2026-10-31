#!/usr/bin/env bash
set -euo pipefail
artifact_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$artifact_root"
app_container=konji-shop-prod-app-1
engine=scripts/seo/product-database-20261031
sha256sum -c "$engine/checksums.sha256"
# Reject unreviewed source before touching the database.
bash scripts/deploy/sync-product-seo-database-20261031.sh --preflight
docker exec -w /var/www/html "$app_container" mkdir -p storage/app/private/seo-repairs
backup_dir="$(docker exec -w /var/www/html "$app_container" mktemp -d storage/app/private/seo-repairs/product-seo-20261031.XXXXXX)"
stage="/tmp/konji-product-seo-db-${backup_dir##*.}"
docker exec "$app_container" mkdir -p "$stage"
for file in Metadata.php DatabaseRepair.php reviewed-titles.php run.php; do
    docker cp "$engine/$file" "$app_container:$stage/$file"
    docker exec "$app_container" php -l "$stage/$file"
done
echo "PRODUCT_SEO_REPAIR_DIRECTORY=$backup_dir"
echo "PRODUCT_SEO_REPAIR_ENGINE=$stage"
docker exec -w /var/www/html "$app_container" php "$stage/run.php" dry-run "$backup_dir"
if [[ "${1:-}" == --dry-run-only ]]; then
    echo 'DATABASE_WRITES=0'
    exit 0
fi
docker exec -w /var/www/html "$app_container" php "$stage/run.php" apply "$backup_dir"
if ! bash scripts/deploy/sync-product-seo-database-20261031.sh; then
    echo "SOURCE_PATCH_FAILED_DATABASE_REMAINS_APPLIED=$backup_dir" >&2
    echo 'Retry the source patch after resolving the failure; the database receipt and backup are retained.' >&2
    exit 1
fi
docker exec -w /var/www/html "$app_container" php "$stage/run.php" verify "$backup_dir"
for cache_check in 1 2; do
    curl --silent --show-error --fail --max-time 120 \
        --resolve staging.ortezka.pl:443:127.0.0.1 \
        --output /dev/null --write-out "PRODUCT_REQUEST=$cache_check HTTP_STATUS=%{http_code}\n" \
        https://staging.ortezka.pl/products/mediven-forte-podkolan-wki-uciskowe-1
done
echo "PRODUCT_SEO_BACKUP=$backup_dir/plan-and-backup.json"
echo 'PRODUCT_SEO_DATABASE_REPAIR=PASS'
