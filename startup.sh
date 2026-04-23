#!/usr/bin/env bash
set -e

echo "[startup] Running migrations..."
php artisan migrate --force --no-interaction

echo "[startup] Seeding coaches..."
php artisan db:seed --class=CoachSeeder --force --no-interaction

echo "[startup] Linking storage..."
php artisan storage:link --force 2>/dev/null || true

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
      --timeout=60 \
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
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
