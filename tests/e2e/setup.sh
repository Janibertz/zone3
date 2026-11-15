#!/usr/bin/env bash
# Frische Datenbank und das Testkonto für die Browser-Tests.
set -euo pipefail
cd "$(dirname "$0")/../.."
source tests/e2e/env.sh
rm -f "$DB_DATABASE"
touch "$DB_DATABASE"
php artisan config:clear --no-interaction >/dev/null
php artisan migrate --force --no-interaction >/dev/null
php artisan db:seed --class=CoachSeeder --force --no-interaction >/dev/null
php artisan testdev:create --no-interaction
