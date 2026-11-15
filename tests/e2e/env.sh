# Umgebung für die Browser-Tests (tests/e2e). Wird von setup.sh und vom
# Webserver in playwright.config.js gelesen. Prozess-Variablen schlagen die
# .env (Dotenv ist unveränderlich), also gilt das hier auch neben einer
# lokalen .env mit echten Schlüsseln.
export APP_ENV=e2e
export APP_DEBUG=true
export APP_URL=http://127.0.0.1:8123
export APP_LOCALE=de
export DB_CONNECTION=sqlite
export DB_DATABASE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/database/e2e.sqlite"
export DB_URL=
export SESSION_DRIVER=file
export CACHE_STORE=file
# Jobs bleiben in der Tabelle liegen; es läuft kein Worker.
export QUEUE_CONNECTION=database
export MAIL_MAILER=array
export LOG_CHANNEL=single
export LOG_LEVEL=info
export BROADCAST_CONNECTION=null
# Nichts darf ein Modell, eine Uhr oder einen Messenger erreichen.
export OPENAI_API_KEY=
export TELEGRAM_BOT_TOKEN=
export GARMIN_CLIENT_ID=
export COROS_ENABLED=false
export POLAR_CLIENT_ID=
export WHOOP_CLIENT_ID=
export INTERVALS_CLIENT_ID=
export VAPID_PUBLIC_KEY=
export VAPID_PRIVATE_KEY=
export OBSERVABILITY_ENABLED=false
export OBSERVABILITY_INSTANT_ALERTS=false
