#!/bin/bash -ex

cd /var/www

export DD_TRACE_CLI_ENABLED=false

# vendor/ arrives via overlayfs from the local checkout. Re-run composer to
# strip dev packages and rebuild the autoloader for the container's PHP version.
# Clear bootstrap cache first or artisan fails on dev-only providers (e.g. facade/ignition).
rm -f /var/www/bootstrap/cache/*.php
composer install --no-dev --no-scripts

cp .env.example .env
# Patch the environment to use SQLite instead of MySQL.
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
sed -i 's/^DB_DATABASE=.*/DB_DATABASE=\/tmp\/database.sqlite/' .env
sed -i '/^DB_HOST=/d' .env
sed -i '/^DB_PORT=/d' .env
sed -i '/^DB_USERNAME=/d' .env
sed -i '/^DB_PASSWORD=/d' .env
touch /tmp/database.sqlite

php artisan package:discover --ansi
php artisan key:generate
php artisan config:cache
php artisan migrate
php artisan db:seed

chown www-data.www-data /tmp/database.sqlite
chown -R www-data.www-data /var/www/storage
mkdir -p /tmp/logs/laravel
chown www-data.www-data /tmp/logs/laravel
