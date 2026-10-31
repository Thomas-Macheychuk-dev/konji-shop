#!/usr/bin/env bash
set -euo pipefail
artifact_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$artifact_root"
containers=(konji-shop-prod-app-1 konji-shop-prod-queue-1 konji-shop-prod-scheduler-1)
roles=(app queue scheduler)
files=(
    app/Http/Controllers/CategoryProductIndexController.php
    resources/views/pages/categories/show.blade.php
    app/Http/Controllers/ProductShowController.php
    resources/content/category-copy.pl.php
)
new_hashes=(
    6fc3a41345be0a0e948c54e1730b5423f23a5b3b2a307d8c8e4284aef02fb885
    638b4839e9d59d2799e5538bc75ded40843ae1b03b7626c14ad3b0a1aa2a18be
    b02838c0c88acc017130d4e113e11ff6015e4bc2782b33257ca49414449a473b
    fd4d50eb0a0cd0690452a903be3e37d3ff641bc0c20ef57f520cb3b9a6f68575
)
old_hashes=(
    d451fa16f0b62a9afc6f77f72c08ccf15c52d7c75b8cfb0fa6cb3dac35ca2d3e
    99aefeb6b1dac8defd43adb00b0d48dbcd0037fb6413b916453776c28960aed7
    2bd619267d26cf981f34579ca400c9e648e8061fd2150911e848f1309c1651d4
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
        [[ "$actual" == "${new_hashes[$i]}" || "$actual" == "${old_hashes[$i]}" || ( "$i" == 0 && "$actual" == "ba118ba079f175ca9aa5341338ab020f43ae8b9fff7d99770958c651c6c99e2f" ) ]] || {
            echo "UNREVIEWED_SOURCE=${containers[$role_index]}:${files[$i]} SHA256=$actual; no live source changed" >&2
            exit 1
        }
        echo "SOURCE_${roles[$role_index]}_${i}=$actual"
    done
done
echo 'ALL_CONTAINER_PREFLIGHT=PASS'
app_container="${containers[0]}"
docker exec -w /var/www/html "$app_container" mkdir -p storage/app/private/ui-backups
backup_dir="$(docker exec -w /var/www/html "$app_container" mktemp -d storage/app/private/ui-backups/seo-category-content-20261031.XXXXXX)"
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
    docker exec -w /var/www/html "$app_container" php artisan view:cache
    docker exec "$app_container" sh -c 'kill -USR2 1'
    echo "SEO_CONTENT_PATCH=FAIL; source rollback attempted. BACKUP=$backup_dir" >&2
    exit 1
}
trap restore_sources ERR
stage_dir=/tmp/konji-seo-category-content-20261031
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
php -l "$stage_dir/2"
php -l "$stage_dir/3"
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
docker exec -w /var/www/html "$app_container" php artisan view:cache
docker exec "$app_container" sh -c 'kill -USR2 1'
trap - ERR
echo "SEO_CONTENT_PATCH_BACKUP=$backup_dir"
echo 'ALL_CONTAINER_SOURCES=PASS'
echo 'DATABASE_WRITES=0'
echo 'SEO_CONTENT_PATCH=PASS'
