#!/bin/bash -e

cd /var/www

composer install
php bin/console doctrine:database:drop --force
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
php bin/console doctrine:fixtures:load -n
chown www-data.www-data var/app.db
