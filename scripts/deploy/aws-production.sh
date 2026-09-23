#!/usr/bin/env bash
set -euo pipefail

APP_PATH="${APP_PATH:-/var/www/konji-shop}"
BRANCH="${BRANCH:-main}"
DEPLOY_SHA="${DEPLOY_SHA:-}"
COMPOSE="docker compose -f docker-compose.prod.yml"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1/up}"

cd "${APP_PATH}"

if [ ! -f .env ]; then
  echo "Missing ${APP_PATH}/.env. Copy .env.production.example to .env and fill production secrets first." >&2
  exit 1
fi

echo "Fetching ${BRANCH}..."
git fetch origin "${BRANCH}"
git checkout "${BRANCH}"

if [ -n "${DEPLOY_SHA}" ]; then
  echo "Deploying exact tested commit ${DEPLOY_SHA}..."

  if ! git cat-file -e "${DEPLOY_SHA}^{commit}" 2>/dev/null; then
    echo "Requested deployment commit ${DEPLOY_SHA} is not available locally after fetch." >&2
    exit 1
  fi

  if ! git merge-base --is-ancestor "${DEPLOY_SHA}" "origin/${BRANCH}"; then
    echo "Requested deployment commit ${DEPLOY_SHA} is not an ancestor of origin/${BRANCH}." >&2
    exit 1
  fi

  CURRENT_SHA="$(git rev-parse HEAD)"

  if [ "${CURRENT_SHA}" != "${DEPLOY_SHA}" ]; then
    if ! git merge-base --is-ancestor "${CURRENT_SHA}" "${DEPLOY_SHA}"; then
      echo "Refusing non-fast-forward production deployment from ${CURRENT_SHA} to ${DEPLOY_SHA}." >&2
      exit 1
    fi

    git merge --ff-only "${DEPLOY_SHA}"
  fi

  ACTUAL_SHA="$(git rev-parse HEAD)"

  if [ "${ACTUAL_SHA}" != "${DEPLOY_SHA}" ]; then
    echo "Production checkout ${ACTUAL_SHA} does not match requested deployment ${DEPLOY_SHA}." >&2
    exit 1
  fi
else
  git pull --ff-only origin "${BRANCH}"
fi

echo "Building production images..."
${COMPOSE} build --pull app web

echo "Starting Redis and app..."
${COMPOSE} up -d redis app

echo "App entrypoint cache refresh is disabled in production; deploy owns bootstrap/cache."

echo "Running migrations..."
${COMPOSE} exec -T app php artisan migrate --force

echo "Refreshing Laravel caches..."
${COMPOSE} exec -T app php artisan optimize:clear
${COMPOSE} exec -T app php artisan config:cache
${COMPOSE} exec -T app php artisan route:cache
${COMPOSE} exec -T app php artisan view:cache
${COMPOSE} exec -T app php artisan event:cache
${COMPOSE} exec -T app php artisan storage:link || true

# This gate is intentionally blocking: an unoptimized PHP/Laravel runtime raises
# TTFB and the EC2 size needed for the same storefront traffic.
echo "Checking production runtime optimization..."
${COMPOSE} exec -T app php artisan shop:check-runtime --json

echo "Starting web, queue and scheduler..."
${COMPOSE} up -d --remove-orphans web queue scheduler

${COMPOSE} exec -T app php artisan queue:restart || true

echo "Running application readiness checks..."
${COMPOSE} exec -T app php artisan shop:check --json || true
${COMPOSE} exec -T app php artisan polkurier:check --json || true

echo "Checking health endpoint: ${HEALTH_URL}"
for attempt in {1..20}; do
  if curl -fsS "${HEALTH_URL}" >/dev/null; then
    echo "Deployment completed successfully."
    exit 0
  fi

  sleep 3
  echo "Waiting for health endpoint... (${attempt}/20)"
done

echo "Deployment finished, but health endpoint did not respond successfully." >&2
${COMPOSE} ps >&2
exit 1
