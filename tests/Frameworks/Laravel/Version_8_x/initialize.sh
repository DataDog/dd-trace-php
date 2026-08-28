#!/bin/bash -ex

cd /var/www

export DD_TRACE_CLI_ENABLED=false

# Clear stale bootstrap cache built with dev dependencies (e.g. facade/ignition).
# Without this, artisan fails with "Class not found" for removed dev providers.
rm -f /var/www/bootstrap/cache/*.php

# The vendor/ directory is pre-installed on the host and mounted via the overlay.
# Run composer install to regenerate the autoloader for the container's PHP version.
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
