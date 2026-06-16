#!/usr/bin/env bash
set -e

echo "[startup] Running migrations..."
php artisan migrate --force --no-interaction

echo "[startup] Seeding coaches..."
php artisan db:seed --class=CoachSeeder --force --no-interaction

echo "[startup] Seeding wiki (initial docs)..."
php artisan wiki:seed --no-interaction || true

echo "[startup] Linking storage..."
php artisan storage:link --force 2>/dev/null || true

echo "[startup] Generating PWA icons..."
php artisan pwa:icons --no-interaction || true

echo "[startup] Caching config/routes/views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[startup] Starting queue worker in background..."
# Restart-loop so the worker comes back up automatically if it exits
(
  while true; do
    php artisan queue:work \
      --sleep=3 \
      --tries=3 \
      --timeout=1800 \
      --max-jobs=500
    echo "[worker] Restarting queue worker..."
    sleep 2
  done
) &

echo "[startup] Starting scheduler in background..."
(
  while true; do
    php artisan schedule:run --no-interaction
    sleep 60
  done
) &

echo "[startup] Starting web server..."
# Run the built-in server with multiple worker processes so a single slow request
# can never block the whole site for other users (defense in depth — heavy work is queued).
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
