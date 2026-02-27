#!/bin/bash -e

cd /var/www

composer install --no-dev
chown -R www-data.www-data vendor
