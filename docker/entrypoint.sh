#!/bin/sh
set -e

echo "Starting Laravel container..."

if [ -n "$DB_HOST" ]; then
  echo "Waiting for database at $DB_HOST..."
  until nc -z "$DB_HOST" "${DB_PORT:-3306}"; do
    sleep 2
  done
fi

php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true
php artisan event:clear || true

php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

exec "$@"
