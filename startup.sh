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

echo "[startup] Caching config/routes/views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[startup] Registering Telegram webhook..."
# Idempotent; ohne TELEGRAM_BOT_TOKEN ein No-op.
php artisan telegram:set-webhook --no-interaction || true

echo "[startup] Starting queue worker in background..."
# Restart-loop so the worker comes back up automatically if it exits.
# `imports` steht mit in der Liste, obwohl nichts mehr darauf schreibt: eine
# Aufgabe, die vom Umbau noch dort liegt, wuerde sonst nie abgearbeitet.
(
  while true; do
    php artisan queue:work \
      --queue=default,imports \
      --sleep=3 \
      --tries=3 \
      --timeout=1800 \
      --max-jobs=500
    echo "[worker] Restarting queue worker..."
    sleep 2
  done
) &

echo "[startup] Starting scheduler in background..."
# `schedule:work` startet `schedule:run` zu Beginn jeder vollen Minute, ohne
# auf den vorigen Lauf zu warten. Die fruehere Schleife `schedule:run; sleep 60`
# brauchte pro Runde 60 s plus Laufzeit, der Takt wanderte, und alle paar
# Dutzend Minuten fiel eine Minute ganz aus. Ein Befehl mit fester Uhrzeit
# (`dailyAt`) lief dann an diesem Tag gar nicht — am 04.11.2026 so passiert:
# Runde um 04:29:59, naechste um 04:31, `garmin:refresh-tokens` (04:30) fehlte.
# Die Neustart-Schleife bleibt, falls der Prozess einmal endet.
(
  while true; do
    php artisan schedule:work --no-interaction
    echo "[scheduler] Restarting scheduler..."
    sleep 2
  done
) &

echo "[startup] Starting web server..."
# Run the built-in server with multiple worker processes so a single slow request
# can never block the whole site for other users (defense in depth — heavy work is queued).
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"
# `--no-reload` ist Pflicht: ohne ignoriert `artisan serve` PHP_CLI_SERVER_WORKERS
# ("Only creating a single server") und es laeuft nur ein einziger Prozess.
# Seit dem ersten Deploy war das so (bemerkt am 04.11.2026 im Log). Neu laden
# muss hier nichts: eine geaenderte Umgebung startet in Coolify den Container neu.
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}" --no-reload
