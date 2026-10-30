#!/usr/bin/env bash
set -euo pipefail
artifact_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$artifact_root"
containers=(konji-shop-prod-app-1 konji-shop-prod-queue-1 konji-shop-prod-scheduler-1)
roles=(app queue scheduler)
files=(
    app/Http/Controllers/CategoryProductIndexController.php
    resources/views/pages/categories/show.blade.php
    resources/views/components/storefront/product-card.blade.php
    resources/views/partials/storefront/header.blade.php
)
new_hashes=(
    ba118ba079f175ca9aa5341338ab020f43ae8b9fff7d99770958c651c6c99e2f
    99aefeb6b1dac8defd43adb00b0d48dbcd0037fb6413b916453776c28960aed7
    a292640a3f8068bd915c6450f3b6a2d858c0d0d899ba6058e0b539c3e904dec0
    c22c1222d36046fbc95ce3f7dcef8914d6f34907d6fafa694d5c73e3effb2352
)
old_hashes=(
    54dc7ad81316a8b12c642cbeaa33707d53019e5412cbdae080bdc27c2e3cb074
    64566735d65c1c493a8128310c68136144263a4d6a32ee5e5f5adccbe44cbda6
    01d3e354cd9cc6d503a87fb5a0dc1381b68189beccbebd96939135f2dfdd408f
    9b8cabffb51281458776fc4d08030fed280ee18e16c7c596647b44aa83d40407
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
        [[ "$actual" == "${new_hashes[$i]}" || "$actual" == "${old_hashes[$i]}" ]] || {
            echo "UNREVIEWED_SOURCE=${containers[$role_index]}:${files[$i]} SHA256=$actual; no live source changed" >&2
            exit 1
        }
        echo "SOURCE_${roles[$role_index]}_${i}=$actual"
    done
done
echo 'ALL_CONTAINER_PREFLIGHT=PASS'
app_container="${containers[0]}"
docker exec -w /var/www/html "$app_container" mkdir -p storage/app/private/ui-backups
backup_dir="$(docker exec -w /var/www/html "$app_container" mktemp -d storage/app/private/ui-backups/catalogue-view-sync-20261030.XXXXXX)"
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
    docker exec -w /var/www/html "$app_container" php artisan view:cache
    docker exec "$app_container" sh -c 'kill -USR2 1'
    echo "VIEW_SYNC=FAIL; source rollback attempted. BACKUP=$backup_dir" >&2
    exit 1
}
trap restore_sources ERR
stage_dir=/tmp/konji-catalogue-view-sync-20261030
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
# Queue's hourly restart can now compile only the same reviewed templates.
docker exec -w /var/www/html "$app_container" php artisan view:cache
docker exec "$app_container" sh -c 'kill -USR2 1'
trap - ERR
echo "VIEW_SYNC_BACKUP=$backup_dir"
echo 'ALL_CONTAINER_SOURCES=PASS'
echo 'DATABASE_WRITES=0'
echo 'VIEW_SYNC=PASS'
