#!/bin/bash -ex

cd /var/www

export DD_TRACE_CLI_ENABLED=false

# vendor/ is not committed (and there is no composer.lock): resolve dependencies for the
# container's PHP version, without dev packages.
composer install --no-interaction --no-dev
chown -R www-data:www-data vendor

# The Apache workers run as www-data; the app may write its logs and cache here.
mkdir -p logs var/cache
chown -R www-data:www-data logs var
