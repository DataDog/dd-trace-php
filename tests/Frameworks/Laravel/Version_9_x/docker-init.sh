#!/bin/bash -ex

cd /var/www

export DD_TRACE_CLI_ENABLED=false

# vendor/ arrives via overlayfs from the local checkout. Re-run composer to
# strip dev packages and rebuild the autoloader for the container's PHP version.
# Clear bootstrap cache first or artisan fails on dev-only providers (e.g. facade/ignition).
rm -f /var/www/bootstrap/cache/*.php
composer install --no-dev --no-scripts

cp .env.example .env
# Pin the fixture to sqlite + file sessions. Lines may be present (older skeletons)
# or absent (Laravel 9.x+ dropped DB_* defaults from .env.example, Laravel 11.x
# defaults SESSION_DRIVER=database); patch when present, append when absent so
# artisan migrate and the HTTP flow both hit a known-writable sqlite file.
ensure_env() {
    local key=$1
    local val=$2
    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${val}|" .env
    else
        echo "${key}=${val}" >> .env
    fi
}
ensure_env DB_CONNECTION sqlite
ensure_env DB_DATABASE /tmp/database.sqlite
ensure_env SESSION_DRIVER file
sed -i '/^DB_HOST=/d;/^DB_PORT=/d;/^DB_USERNAME=/d;/^DB_PASSWORD=/d' .env
# Apache workers run as www-data; the migrate/seed step below runs as root and
# would otherwise leave the sqlite file root-owned (read-only for www-data).
touch /tmp/database.sqlite
chmod 666 /tmp/database.sqlite

php artisan package:discover --ansi
php artisan key:generate
php artisan config:cache
php artisan migrate
php artisan db:seed

chown www-data:www-data /tmp/database.sqlite
chmod 666 /tmp/database.sqlite
chown -R www-data:www-data /var/www/storage
mkdir -p /tmp/logs/laravel
chown www-data:www-data /tmp/logs/laravel
