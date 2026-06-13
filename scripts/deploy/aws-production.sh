#!/usr/bin/env bash
set -euo pipefail

APP_PATH="${APP_PATH:-/var/www/konji-shop}"
BRANCH="${BRANCH:-main}"
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
git pull --ff-only origin "${BRANCH}"

echo "Building production images..."
${COMPOSE} build --pull app web

echo "Starting Redis and app..."
${COMPOSE} up -d redis app

echo "Running migrations..."
${COMPOSE} exec -T app php artisan migrate --force

echo "Refreshing Laravel caches..."
${COMPOSE} exec -T app php artisan optimize:clear
${COMPOSE} exec -T app php artisan config:cache
${COMPOSE} exec -T app php artisan route:cache
${COMPOSE} exec -T app php artisan view:cache
${COMPOSE} exec -T app php artisan event:cache || true
${COMPOSE} exec -T app php artisan storage:link || true

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
