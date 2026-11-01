#!/usr/bin/env bash
set -euo pipefail
artifact_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$artifact_root"
containers=(konji-shop-prod-app-1 konji-shop-prod-queue-1 konji-shop-prod-scheduler-1)
roles=(app queue scheduler)
# Only extend the existing guarded alias manifest; the first six pairs are unchanged.
files=(resources/seo/ortezka/reviewed-product-aliases-20261101.json)
new_hashes=(2be6f5fffdfa4f9a6c1d61cea2232cf353613cd38bb899767d182a5138d4c379)
old_hashes=(d795a234da7ef6ae56c479b2c496e3de3f2834dbfa81c6b5106aeecc8ee0b58c)
for i in "${!files[@]}"; do
    [[ "$(sha256sum "${files[$i]}" | cut -d' ' -f1)" == "${new_hashes[$i]}" ]]
done
# This patch never changes nginx. Refuse an unreviewed legacy redirect map.
legacy_hash="$(docker exec konji-shop-prod-web-1 sha256sum /etc/nginx/conf.d/00-legacy-seo-product-map.conf)"
[[ "${legacy_hash%% *}" == df806bf23d22c0420fda442811b5bf9137bd5c6ed2e64023898eac496cf58bee ]]
for container in "${containers[@]}"; do
    helper_hash="$(docker exec -w /var/www/html "$container" sha256sum app/Support/Seo/ReviewedProductAliases.php)"
    [[ "${helper_hash%% *}" == 73c1a073ffdbee2cd38422e9da7507d561a54cf01144694d7c0d6f4344000e2b ]]
    controller_hash="$(docker exec -w /var/www/html "$container" sha256sum app/Http/Controllers/ProductShowController.php)"
    [[ "${controller_hash%% *}" == 3807c9d676b410b07693b4c33dacd070e45d4d38e6ca37aadbda92c05aa856cd ]]
    for i in "${!files[@]}"; do
        actual="$(docker exec -w /var/www/html "$container" sh -c 'if test -f "$1"; then sha256sum "$1"; else echo MISSING; fi' sh "${files[$i]}")"
        actual="${actual%% *}"
        [[ "$actual" == "${old_hashes[$i]}" || "$actual" == "${new_hashes[$i]}" ]] || { echo "UNREVIEWED_SOURCE=$container:${files[$i]} SHA256=$actual" >&2; exit 1; }
    done
done
echo 'ALL_CONTAINER_PREFLIGHT=PASS'
app_container="${containers[0]}"
backup_dir="$(docker exec -w /var/www/html "$app_container" sh -c 'umask 077; mkdir -p storage/app/private/ui-backups; mktemp -d storage/app/private/ui-backups/product-consolidation-final-20261101.XXXXXX')"
for role_index in "${!containers[@]}"; do
    docker exec -i -w /var/www/html "${containers[$role_index]}" bash -s -- "$backup_dir/${roles[$role_index]}" "${files[@]}" <<'BACKUP'
set -euo pipefail
umask 077
backup_dir=$1; shift
for file in "$@"; do
    mkdir -p "$backup_dir/$(dirname "$file")"
    if [[ -f "$file" ]]; then cp -p "$file" "$backup_dir/$file"; cmp "$file" "$backup_dir/$file"; else touch "$backup_dir/$file.missing"; fi
done
BACKUP
done
restore_sources() {
    trap - ERR
    set +e
    for role_index in "${!containers[@]}"; do
        docker exec -i -w /var/www/html "${containers[$role_index]}" bash -s -- "$backup_dir/${roles[$role_index]}" "${files[@]}" <<'RESTORE'
set -euo pipefail
backup_dir=$1; shift
for file in "$@"; do
    if [[ -f "$backup_dir/$file.missing" ]]; then rm -f "$file"; else cp -p "$backup_dir/$file" "$file"; fi
done
RESTORE
    done
    docker exec "$app_container" sh -c 'kill -USR2 1'
    echo "FINAL_CONSOLIDATION_CODE_PATCH=FAIL BACKUP=$backup_dir" >&2
    exit 1
}
trap restore_sources ERR
stage_dir="$(docker exec "$app_container" sh -c 'mktemp -d /tmp/konji-consolidation-code.XXXXXX')"
for container in "${containers[@]}"; do
    docker exec "$container" mkdir -p "$stage_dir"
    for i in "${!files[@]}"; do docker cp "${files[$i]}" "$container:$stage_dir/$i" >/dev/null; done
    docker exec "$container" php -r 'json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);' "$stage_dir/0"
done
for container in "${containers[@]}"; do
    docker exec -i -w /var/www/html "$container" bash -s -- "$stage_dir" "${files[@]}" <<'APPLY'
set -euo pipefail
stage_dir=$1; shift
i=0
for file in "$@"; do
    mkdir -p "$(dirname "$file")"
    cp "$stage_dir/$i" "$file.consolidation.tmp"
    mv "$file.consolidation.tmp" "$file"
    i=$((i + 1))
done
APPLY
    for i in "${!files[@]}"; do
        actual="$(docker exec -w /var/www/html "$container" sha256sum "${files[$i]}")"
        [[ "${actual%% *}" == "${new_hashes[$i]}" ]]
    done
done
docker exec "$app_container" sh -c 'kill -USR2 1'
trap - ERR
echo "FINAL_CONSOLIDATION_CODE_BACKUP=$backup_dir"
echo 'ALL_CONTAINER_SOURCES=PASS'
echo 'DATABASE_WRITES=0'
echo 'FINAL_CONSOLIDATION_CODE_PATCH=PASS'
