#!/bin/bash -e

cd /var/www

export DD_TRACE_CLI_ENABLED=false

composer install --no-dev
chown -R www-data.www-data vendor

cp .env.example .env
php artisan key:generate
php artisan config:cache
touch /tmp/database.sqlite
php artisan migrate
php artisan db:seed
chown www-data.www-data /tmp/database.sqlite
chown -R www-data.www-data /var/www/storage
mkdir -p /tmp/logs/laravel
chown www-data.www-data /tmp/logs/laravel
