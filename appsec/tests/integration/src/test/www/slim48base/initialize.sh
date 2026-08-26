#!/bin/bash -e

cd /var/www

export DD_TRACE_CLI_ENABLED=false

cp -a /project/tests/Frameworks/Slim/Version_4_8/. .
cp /test-resources/app/routes.php app/routes.php

composer install --no-interaction --no-dev
chown -R www-data:www-data vendor logs
