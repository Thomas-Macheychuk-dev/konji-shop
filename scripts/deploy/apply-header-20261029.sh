#!/usr/bin/env bash
set -euo pipefail

artifact_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
container_name="${KONJI_HEADER_CONTAINER:-konji-shop-prod-app-1}"
stage_dir=/tmp/konji-header-20261029

cd "$artifact_root"
sha256sum -c - <<'HASHES'
c22c1222d36046fbc95ce3f7dcef8914d6f34907d6fafa694d5c73e3effb2352  resources/views/partials/storefront/header.blade.php
12dbd56b5a5bfa98c50554ab893ba9da2e6f444b44b388c1f3ab7f89d5470f4b  routes/web.php
1cce3f667cb90135315110cb9c1b310bd96b2599beff860f689f62193ed53440  resources/views/pages/legal/nfz.blade.php
HASHES

docker exec "$container_name" mkdir -p "$stage_dir"
docker cp resources/views/partials/storefront/header.blade.php "$container_name:$stage_dir/header.blade.php"
docker cp routes/web.php "$container_name:$stage_dir/web.php"
docker cp resources/views/pages/legal/nfz.blade.php "$container_name:$stage_dir/nfz.blade.php"

docker exec -i -w /var/www/html "$container_name" bash -s <<'CONTAINER'
set -euo pipefail
stage_dir=/tmp/konji-header-20261029
backup_dir=storage/app/private/ui-backups/header-20261029

# Refuse to replace unrelated source changes or an existing NFZ page.
sha256sum -c - <<'HASHES'
9b8cabffb51281458776fc4d08030fed280ee18e16c7c596647b44aa83d40407  resources/views/partials/storefront/header.blade.php
2cc7ad013834f230c8be8e5db05a940224eaa77b7be8dbf181fe7679b4fb929b  routes/web.php
HASHES
test ! -e resources/views/pages/legal/nfz.blade.php
php -l "$stage_dir/web.php"

umask 077
mkdir -p storage/app/private/ui-backups
mkdir "$backup_dir"
cp resources/views/partials/storefront/header.blade.php "$backup_dir/header.blade.php"
cp routes/web.php "$backup_dir/web.php"

restore_header() {
    trap - ERR
    set +e
    cp "$backup_dir/header.blade.php" resources/views/partials/storefront/header.blade.php
    cp "$backup_dir/web.php" routes/web.php
    rm -f resources/views/pages/legal/nfz.blade.php
    php artisan route:cache
    php artisan view:cache
    kill -USR2 1
    echo 'HEADER_PATCH=FAIL; rollback attempted, review the preceding output' >&2
    exit 1
}
trap restore_header ERR

cp "$stage_dir/nfz.blade.php" resources/views/pages/legal/nfz.blade.php
cp "$stage_dir/web.php" routes/web.php
php artisan route:cache
cp "$stage_dir/header.blade.php" resources/views/partials/storefront/header.blade.php
php artisan view:cache

sha256sum -c - <<'HASHES'
c22c1222d36046fbc95ce3f7dcef8914d6f34907d6fafa694d5c73e3effb2352  resources/views/partials/storefront/header.blade.php
12dbd56b5a5bfa98c50554ab893ba9da2e6f444b44b388c1f3ab7f89d5470f4b  routes/web.php
1cce3f667cb90135315110cb9c1b310bd96b2599beff860f689f62193ed53440  resources/views/pages/legal/nfz.blade.php
HASHES

# Production OPcache does not check source timestamps.
kill -USR2 1
trap - ERR
echo "HEADER_BACKUP=$backup_dir"
echo 'HEADER_PATCH=PASS'
CONTAINER
