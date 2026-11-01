#!/usr/bin/env bash
set -euo pipefail

mode="${1:-dry-run}"
container="${KONJI_APP_CONTAINER:-konji-shop-prod-app-1}"
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../../.." && pwd)"
case "$mode" in dry-run|apply|verify|rollback) ;; *) echo 'Usage: repair.sh dry-run|apply|verify|rollback [PRIVATE_BACKUP_DIRECTORY]' >&2; exit 1 ;; esac
cd "$repo_root"
sha256sum -c scripts/seo/source-cleanup-20261101/checksums.sha256
remote="$(docker exec --user 0 "$container" sh -c 'umask 077; mktemp -d /tmp/konji-seo-editorial-repair.XXXXXX')"
docker exec --user 0 "$container" mkdir -p "$remote/scripts/seo/product-database-20261031"
docker cp scripts/seo/source-cleanup-20261101 "$container:$remote/scripts/seo/" >/dev/null
docker cp scripts/seo/colour-correction-20261101 "$container:$remote/scripts/seo/" >/dev/null
docker cp scripts/seo/editorial-repair-20261031 "$container:$remote/scripts/seo/" >/dev/null
docker cp scripts/seo/product-database-20261031/Metadata.php "$container:$remote/scripts/seo/product-database-20261031/Metadata.php" >/dev/null
docker exec --user 0 -w "$remote" "$container" sha256sum -c scripts/seo/source-cleanup-20261101/checksums.sha256
for file in SourceRepair.php run.php; do
    docker exec --user 0 "$container" php -l "$remote/scripts/seo/source-cleanup-20261101/$file"
done
backup="${2:-}"
if [[ "$mode" == dry-run || "$mode" == apply ]]; then
    if [[ -n "$backup" ]]; then echo 'A fresh backup directory is created for each dry run/apply.' >&2; exit 1; fi
    backup="$(docker exec --user 0 -w /var/www/html "$container" sh -c 'umask 077; mkdir -p storage/app/private/seo-repairs; mktemp -d storage/app/private/seo-repairs/source-cleanup-20261101.XXXXXX')"
    docker exec --user 0 -w /var/www/html "$container" php "$remote/scripts/seo/source-cleanup-20261101/run.php" dry-run "$backup"
fi
if [[ "$mode" != dry-run ]]; then
    if [[ -z "$backup" ]]; then echo 'Verification/rollback requires the printed private backup directory.' >&2; exit 1; fi
    docker exec --user 0 -w /var/www/html "$container" php "$remote/scripts/seo/source-cleanup-20261101/run.php" "$mode" "$backup"
    if [[ "$mode" == apply ]]; then
        docker exec --user 0 -w /var/www/html "$container" php "$remote/scripts/seo/source-cleanup-20261101/run.php" verify "$backup"
    fi
fi
printf 'EDITORIAL_BACKUP=%s\nEDITORIAL_RUNNER=%s\n' "$backup" "$remote/scripts/seo/source-cleanup-20261101/run.php"
