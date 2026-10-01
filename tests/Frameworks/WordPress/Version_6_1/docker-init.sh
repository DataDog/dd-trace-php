#!/bin/bash -ex

export DD_TRACE_CLI_ENABLED=false

# Serve from /var/www directly instead of /var/www/public, since the shared
# WordPress tree is laid at /var/www root (not under a public/ subdir). This
# way edits in the host checkout are reflected immediately through the overlay
# mount, without re-running this init.
sed -i 's!/var/www/public!/var/www!g' /etc/apache2/sites-available/php-site.conf
service apache2 reload 2>/dev/null || service apache2 restart || true

# Download WP-CLI for use by WordPressTests.groovy's @BeforeAll install step.
curl -sf https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp
chmod +x /usr/local/bin/wp
