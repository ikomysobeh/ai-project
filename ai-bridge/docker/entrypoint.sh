#!/bin/sh
set -e

cd /var/www/html

# Dev convenience: if the named volume for vendor/ came up empty
# (e.g. composer.json changed since the image was built), install now.
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ missing, running composer install..."
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
    echo "[entrypoint] .env missing, copying from .env.example"
    cp .env.example .env
fi

if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    echo "[entrypoint] generating APP_KEY..."
    php artisan key:generate --force
fi

if [ -n "$DB_HOST" ]; then
    echo "[entrypoint] waiting for database at $DB_HOST:${DB_PORT:-5432}..."
    until php -r "new PDO('pgsql:host=$DB_HOST;port=${DB_PORT:-5432};dbname=$DB_DATABASE', '$DB_USERNAME', '$DB_PASSWORD');" 2>/dev/null; do
        sleep 1
    done
    echo "[entrypoint] database is up."
fi

# Only one service (the "app" container, RUN_MIGRATIONS=true) should run
# migrations/storage:link, so the queue worker starting in parallel doesn't
# race it on the same migrations table.
if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force
    php artisan storage:link 2>/dev/null || true
fi

mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Only the vite service (RUN_NPM_INSTALL=true) needs node_modules; app/queue
# don't run npm and skip this so they don't pay the install cost on boot.
if [ "$RUN_NPM_INSTALL" = "true" ] && [ ! -f node_modules/.bin/vite ]; then
    echo "[entrypoint] node_modules/ missing, running npm ci..."
    npm ci
fi

exec "$@"
