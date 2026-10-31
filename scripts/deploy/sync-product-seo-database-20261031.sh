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
    d9cb6a8113545c6812bcd6b34dd4fee82bec5ef68a847460175d89ebdd38cd66
)
old_hashes=(
    659a31d7f756b9ddb716796f90efc5c6dfa6be2892b745c16ccf536c0bdcb366
)
# Validate every input and every container before changing any live source.
for i in "${!files[@]}"; do
    actual="$(sha256sum "${files[$i]}" | cut -d' ' -f1)"
    [[ "$actual" == "${new_hashes[$i]}" ]] || { echo "CANDIDATE_MISMATCH=${files[$i]}" >&2; exit 1; }
done
for role_index in "${!containers[@]}"; do
    hashes="$(docker exec -i -w /var/www/html "${containers[$role_index]}" bash -s -- "${files[@]}" <<'HASHES'
set -euo pipefail
for file in "$@"; do
    if [[ -f "$file" ]]; then sha256sum "$file"; else echo "absent  $file"; fi
done
HASHES
)"
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
if [[ "${1:-}" == --preflight ]]; then exit 0; fi
app_container="${containers[0]}"
docker exec -w /var/www/html "$app_container" mkdir -p storage/app/private/ui-backups
backup_dir="$(docker exec -w /var/www/html "$app_container" mktemp -d storage/app/private/ui-backups/product-seo-database-20261031.XXXXXX)"
# The backup volume is shared, so use a separate directory for each role.
for role_index in "${!containers[@]}"; do
    docker exec -i -w /var/www/html "${containers[$role_index]}" bash -s -- "$backup_dir/${roles[$role_index]}" "${files[@]}" <<'BACKUP'
set -euo pipefail
umask 077
backup_dir=$1
shift
for file in "$@"; do
    mkdir -p "$backup_dir/$(dirname "$file")"
    if [[ -f "$file" ]]; then
        cp -p "$file" "$backup_dir/$file"
        cmp "$file" "$backup_dir/$file"
    else
        touch "$backup_dir/$file.absent"
    fi
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
    if [[ -f "$backup_dir/$file.absent" ]]; then
        rm -f "$file"
    else
        cp -p "$backup_dir/$file" "$file"
    fi
done
RESTORE
    done
    docker exec "$app_container" sh -c 'kill -USR2 1'
    echo "PRODUCT_SEO_SOURCE_PATCH=FAIL; source rollback attempted. BACKUP=$backup_dir" >&2
    exit 1
}
trap restore_sources ERR
stage_dir=/tmp/konji-product-seo-database-20261031
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
    mkdir -p "$(dirname "$file")"
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
docker exec "$app_container" sh -c 'kill -USR2 1'
trap - ERR
echo "PRODUCT_SEO_SOURCE_PATCH_BACKUP=$backup_dir"
echo 'ALL_CONTAINER_SOURCES=PASS'
echo 'DATABASE_WRITES=0'
echo 'PRODUCT_SEO_SOURCE_PATCH=PASS'
