#!/bin/bash -ex

cd /var/www

export DD_TRACE_CLI_ENABLED=false

# Apache in the appsec container serves /var/www/public, but the shared
# WordPress tree lays files at /var/www root. Mirror the tree into public/
# so Apache can find it. OverlayFS avoids duplicating the lower-layer bytes.
mkdir -p /var/www/public
cp -a /test-resources/. /var/www/public/

# Download WP-CLI for use by WordPressTests.groovy's @BeforeAll install step.
curl -sf https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp
chmod +x /usr/local/bin/wp

chown -R www-data:www-data /var/www/public
