#!/bin/bash -ex

cd /var/www/public

export DD_TRACE_CLI_ENABLED=false

# Copy WordPress source from the project mount
cp -a /project/tests/Frameworks/WordPress/Version_6_1/. .
# Restore our custom files (the cp above overwrites them in the overlay upper dir)
cp /test-resources/public/wp-config.php wp-config.php
cp /test-resources/public/index.php index.php
cp /test-resources/public/login_trigger.php login_trigger.php
cp /test-resources/public/hello.php hello.php

# Download WP-CLI
curl -sf https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp
chmod +x /usr/local/bin/wp

chown -R www-data:www-data /var/www/public
