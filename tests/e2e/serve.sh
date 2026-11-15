#!/usr/bin/env bash
# Webserver für die Browser-Tests. php -S statt artisan serve: der Kindprozess
# erbt so die ganze Umgebung aus env.sh.
set -euo pipefail
cd "$(dirname "$0")/../.."
source tests/e2e/env.sh
cd public
exec php -S 127.0.0.1:8123 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
