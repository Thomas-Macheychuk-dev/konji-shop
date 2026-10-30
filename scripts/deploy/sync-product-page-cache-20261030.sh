#!/usr/bin/env bash
set -euo pipefail
artifact_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$artifact_root"
containers=(konji-shop-prod-app-1 konji-shop-prod-queue-1 konji-shop-prod-scheduler-1)
roles=(app queue scheduler)
files=(
    app/Http/Controllers/ProductShowController.php
)
new_hashes=(
    2bd619267d26cf981f34579ca400c9e648e8061fd2150911e848f1309c1651d4
)
old_hashes=(
    bd12e591bfde71f33ce4ec8e39a643a1932adc4c1f2f89dc3ad0429b83dae0c0
)
# Validate every input and every container before changing any live source.
for i in "${!files[@]}"; do
    actual="$(sha256sum "${files[$i]}" | cut -d' ' -f1)"
    [[ "$actual" == "${new_hashes[$i]}" ]] || { echo "CANDIDATE_MISMATCH=${files[$i]}" >&2; exit 1; }
done
for role_index in "${!containers[@]}"; do
    hashes="$(docker exec -w /var/www/html "${containers[$role_index]}" sha256sum "${files[@]}")"
    mapfile -t lines <<< "$hashes"
    [[ ${#lines[@]} == ${#files[@]} ]] || exit 1
    for i in "${!files[@]}"; do
        actual="${lines[$i]%% *}"
        [[ "$actual" == "${new_hashes[$i]}" || "$actual" == "${old_hashes[$i]}" || "$actual" == "9cf6673fb0179a6f22bd9ea03064f5bd1ce5cdaa04f9241e52594485efe5d6f9" ]] || {
            echo "UNREVIEWED_SOURCE=${containers[$role_index]}:${files[$i]} SHA256=$actual; no live source changed" >&2
            exit 1
        }
        echo "SOURCE_${roles[$role_index]}_${i}=$actual"
    done
done
echo 'ALL_CONTAINER_PREFLIGHT=PASS'
app_container="${containers[0]}"
docker exec -w /var/www/html "$app_container" mkdir -p storage/app/private/ui-backups
backup_dir="$(docker exec -w /var/www/html "$app_container" mktemp -d storage/app/private/ui-backups/product-page-cache-20261030.XXXXXX)"
# The backup volume is shared, so use a separate directory for each role.
for role_index in "${!containers[@]}"; do
    docker exec -i -w /var/www/html "${containers[$role_index]}" bash -s -- "$backup_dir/${roles[$role_index]}" "${files[@]}" <<'BACKUP'
set -euo pipefail
umask 077
backup_dir=$1
shift
for file in "$@"; do
    mkdir -p "$backup_dir/$(dirname "$file")"
    cp -p "$file" "$backup_dir/$file"
    cmp "$file" "$backup_dir/$file"
done
BACKUP
done
restore_sources() {
    trap - ERR
    set +e
    for role_index in "${!containers[@]}"; do
        docker exec -i -w /var/www/html "${containers[$role_index]}" bash -s -- "$backup_dir/${roles[$role_index]}" "${files[@]}" <<'RESTORE'
set -euo pipefail
backup_dir=$1
shift
for file in "$@"; do
    cp -p "$backup_dir/$file" "$file"
done
RESTORE
    done
    docker exec "$app_container" sh -c 'kill -USR2 1'
    echo "PRODUCT_PAGE_CACHE_PATCH=FAIL; source rollback attempted. BACKUP=$backup_dir" >&2
    exit 1
}
trap restore_sources ERR
stage_dir=/tmp/konji-product-page-cache-20261030
for container_name in "${containers[@]}"; do
    docker exec "$container_name" mkdir -p "$stage_dir"
    for i in "${!files[@]}"; do
        docker cp "${files[$i]}" "$container_name:$stage_dir/$i"
    done
    docker exec -i -w /var/www/html "$container_name" bash -s -- "$stage_dir" "${files[@]}" <<'APPLY'
set -euo pipefail
stage_dir=$1
shift
php -l "$stage_dir/0"
i=0
for file in "$@"; do
    cp "$stage_dir/$i" "$file"
    i=$((i + 1))
done
APPLY
    hashes="$(docker exec -w /var/www/html "$container_name" sha256sum "${files[@]}")"
    mapfile -t lines <<< "$hashes"
    [[ ${#lines[@]} == ${#files[@]} ]]
    for i in "${!files[@]}"; do
        [[ "${lines[$i]%% *}" == "${new_hashes[$i]}" ]]
    done
done
# Reload app OPcache after all roles have the reviewed controller source.
docker exec "$app_container" sh -c 'kill -USR2 1'
trap - ERR
echo "PRODUCT_PAGE_CACHE_PATCH_BACKUP=$backup_dir"
echo 'ALL_CONTAINER_SOURCES=PASS'
echo 'DATABASE_WRITES=0'
echo 'PRODUCT_PAGE_CACHE_PATCH=PASS'
