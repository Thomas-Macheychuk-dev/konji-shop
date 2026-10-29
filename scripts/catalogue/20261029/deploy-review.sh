#!/usr/bin/env bash
set -euo pipefail
artifact_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
container_name="${KONJI_REVIEW_CONTAINER:-konji-shop-prod-app-1}"
stage_dir=/tmp/konji-catalogue-review-20261029
resource_dir=/tmp/konji-catalogue-review-resources
cd "$artifact_root"
docker exec "$container_name" mkdir -p "$stage_dir" "$resource_dir"
for script in run.php followup.php review.php test-review.php; do
    docker cp "scripts/catalogue/20261029/$script" "$container_name:$stage_dir/$script"
done
for resource in plan.json followup.json review.json; do
    docker cp "resources/catalogue/20261029/$resource" "$container_name:$resource_dir/$resource"
done
docker exec -w /var/www/html "$container_name" php "$stage_dir/test-review.php" \
    --source=/tmp/category-audit.json \
    --original-plan="$resource_dir/plan.json" \
    --followup="$resource_dir/followup.json" \
    --plan="$resource_dir/review.json"
docker exec -w /var/www/html "$container_name" php "$stage_dir/review.php" \
    --mode=dry-run --plan="$resource_dir/review.json"
bash scripts/catalogue/20261029/apply-review-code.sh
docker exec -w /var/www/html "$container_name" php "$stage_dir/review.php" \
    --mode=apply --plan="$resource_dir/review.json"
docker exec -w /var/www/html "$container_name" php "$stage_dir/review.php" \
    --mode=verify --plan="$resource_dir/review.json"
