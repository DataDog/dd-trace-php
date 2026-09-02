#!/bin/bash -e

cd /var/www

export DD_TRACE_CLI_ENABLED=false

composer install
php bin/console doctrine:database:drop --force
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
php bin/console doctrine:fixtures:load -n
chown -R www-data:www-data var
