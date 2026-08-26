#!/bin/bash -e

mkdir -p /var/www/public
cp -asn /project/tests/Frameworks/CodeIgniter/Version_2_2/. /var/www/public
ln -sfn /test-resources/public/.htaccess /var/www/public/.htaccess
ln -sfn /test-resources/public/application/config/routes.php \
    /var/www/public/application/config/routes.php
ln -sfn /test-resources/public/application/controllers/normalized.php \
    /var/www/public/application/controllers/normalized.php
chown www-data:www-data \
    /var/www/public/application/cache \
    /var/www/public/application/logs
