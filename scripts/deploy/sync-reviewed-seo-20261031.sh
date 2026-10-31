#!/usr/bin/env bash
set -euo pipefail
artifact_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$artifact_root"
containers=(konji-shop-prod-app-1 konji-shop-prod-queue-1 konji-shop-prod-scheduler-1)
roles=(app queue scheduler)
files=(
    app/Http/Controllers/ProductShowController.php
    resources/content/category-copy.pl.php
    resources/content/product-title-copy.pl.php
)
new_hashes=(
    659a31d7f756b9ddb716796f90efc5c6dfa6be2892b745c16ccf536c0bdcb366
    3f63e1ddc39d9f709c8f35f510175fb50118c5072a5fcdf35ae36a3b8289432f
    4675b31333b7dc5411c35f8125cf037d05e6f552e43e4113144aa2dbc7c74393
)
old_hashes=(
    b02838c0c88acc017130d4e113e11ff6015e4bc2782b33257ca49414449a473b
    fd4d50eb0a0cd0690452a903be3e37d3ff641bc0c20ef57f520cb3b9a6f68575
    absent
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
app_container="${containers[0]}"
docker exec -w /var/www/html "$app_container" mkdir -p storage/app/private/ui-backups
backup_dir="$(docker exec -w /var/www/html "$app_container" mktemp -d storage/app/private/ui-backups/seo-reviewed-titles-20261031.XXXXXX)"
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
    echo "SEO_REVIEW_PATCH=FAIL; source rollback attempted. BACKUP=$backup_dir" >&2
    exit 1
}
trap restore_sources ERR
stage_dir=/tmp/konji-seo-reviewed-titles-20261031
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
php -l "$stage_dir/1"
php -l "$stage_dir/2"
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
echo "SEO_REVIEW_PATCH_BACKUP=$backup_dir"
echo 'ALL_CONTAINER_SOURCES=PASS'
echo 'DATABASE_WRITES=0'
echo 'SEO_REVIEW_PATCH=PASS'
