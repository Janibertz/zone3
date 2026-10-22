# syntax=docker/dockerfile:1

# ---------------------------------------------------------------
# Basis: offizielles PHP 8.4 (bekommt bei jedem Rebuild den
# aktuellen 8.4-Patch) + benötigte Extensions + Composer
# ---------------------------------------------------------------
FROM php:8.4-cli-bookworm AS base

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql bcmath gd intl zip opcache pcntl gmp exif @composer \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Gleicher Pfad wie bei Nixpacks -> Persistent-Storage-Mounts bleiben gültig
WORKDIR /app

# ---------------------------------------------------------------
# Build-Stage: Composer-Dependencies + Vite-Build (braucht Node)
# ---------------------------------------------------------------
FROM base AS build

RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
 && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && rm -rf /var/lib/apt/lists/*

# VITE_*-Variablen müssen zur Build-Zeit bekannt sein
# (in Coolify als "Available at Buildtime" markieren)
ARG VITE_APP_NAME
ARG VITE_APP_URL

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && npm run build \
 && rm -rf node_modules

# ---------------------------------------------------------------
# Runtime: nur PHP, ohne Node
# ---------------------------------------------------------------
FROM base

COPY --from=build /app /app

ENV PORT=8080 \
    PHP_CLI_SERVER_WORKERS=8

EXPOSE 8080

CMD ["bash", "startup.sh"]
