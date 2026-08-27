#!/bin/sh
set -e

echo "Starting Laravel container..."

if [ -n "$DB_HOST" ]; then
  echo "Waiting for database at $DB_HOST..."
  until nc -z "$DB_HOST" "${DB_PORT:-3306}"; do
    sleep 2
  done
fi

case "${LARAVEL_CACHE_ON_BOOT:-true}" in
  1|true|TRUE|yes|YES|on|ON)
    echo "Refreshing Laravel caches on container start..."

    if [ "${APP_ENV:-local}" = "production" ]; then
      # Queue/scheduler containers are created from the same immutable image as
      # PHP-FPM but have their own writable layer, so build their runtime caches
      # before the long-running command starts.
      php artisan optimize:clear
      php artisan optimize
    else
      php artisan config:clear || true
      php artisan route:clear || true
      php artisan view:clear || true
      php artisan event:clear || true

      php artisan config:cache || true
      php artisan route:cache || true
      php artisan view:cache || true
    fi
    ;;
  *)
    echo "Skipping Laravel cache refresh on container start (LARAVEL_CACHE_ON_BOOT=${LARAVEL_CACHE_ON_BOOT:-false})."
    ;;
esac

exec "$@"
