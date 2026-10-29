#!/usr/bin/env bash
set -euo pipefail
artifact_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
container_name="${KONJI_REVIEW_CONTAINER:-konji-shop-prod-app-1}"
stage_dir=/tmp/konji-catalogue-review-code
cd "$artifact_root"
sha256sum -c - <<'HASHES'
ba118ba079f175ca9aa5341338ab020f43ae8b9fff7d99770958c651c6c99e2f  app/Http/Controllers/CategoryProductIndexController.php
99aefeb6b1dac8defd43adb00b0d48dbcd0037fb6413b916453776c28960aed7  resources/views/pages/categories/show.blade.php
HASHES
docker exec "$container_name" mkdir -p "$stage_dir"
docker cp app/Http/Controllers/CategoryProductIndexController.php "$container_name:$stage_dir/CategoryProductIndexController.php"
docker cp resources/views/pages/categories/show.blade.php "$container_name:$stage_dir/show.blade.php"
docker exec -i -w /var/www/html "$container_name" bash -s <<'CONTAINER'
set -euo pipefail
stage_dir=/tmp/konji-catalogue-review-code
backup_dir=storage/app/private/ui-backups/catalogue-review-20261029
controller=app/Http/Controllers/CategoryProductIndexController.php
view=resources/views/pages/categories/show.blade.php
if [[ "$(sha256sum "$controller" | cut -d' ' -f1)" == ba118ba079f175ca9aa5341338ab020f43ae8b9fff7d99770958c651c6c99e2f && "$(sha256sum "$view" | cut -d' ' -f1)" == 99aefeb6b1dac8defd43adb00b0d48dbcd0037fb6413b916453776c28960aed7 ]]; then
    echo 'REVIEW_CODE_ALREADY_CORRECT=1'
    echo 'REVIEW_CODE_PATCH=PASS'
    exit 0
fi
sha256sum -c - <<'HASHES'
54dc7ad81316a8b12c642cbeaa33707d53019e5412cbdae080bdc27c2e3cb074  app/Http/Controllers/CategoryProductIndexController.php
64566735d65c1c493a8128310c68136144263a4d6a32ee5e5f5adccbe44cbda6  resources/views/pages/categories/show.blade.php
HASHES
php -l "$stage_dir/CategoryProductIndexController.php"
umask 077
mkdir -p storage/app/private/ui-backups
mkdir "$backup_dir"
cp "$controller" "$backup_dir/CategoryProductIndexController.php"
cp "$view" "$backup_dir/show.blade.php"
restore_review_code() {
    trap - ERR
    set +e
    cp "$backup_dir/CategoryProductIndexController.php" "$controller"
    cp "$backup_dir/show.blade.php" "$view"
    php artisan view:cache
    kill -USR2 1
    echo 'REVIEW_CODE_PATCH=FAIL; source rollback attempted, inspect output' >&2
    exit 1
}
trap restore_review_code ERR
cp "$stage_dir/CategoryProductIndexController.php" "$controller"
cp "$stage_dir/show.blade.php" "$view"
php artisan view:cache
sha256sum -c - <<'HASHES'
ba118ba079f175ca9aa5341338ab020f43ae8b9fff7d99770958c651c6c99e2f  app/Http/Controllers/CategoryProductIndexController.php
99aefeb6b1dac8defd43adb00b0d48dbcd0037fb6413b916453776c28960aed7  resources/views/pages/categories/show.blade.php
HASHES
kill -USR2 1
trap - ERR
echo "REVIEW_CODE_BACKUP=$backup_dir"
echo 'REVIEW_CODE_PATCH=PASS'
CONTAINER
