#!/bin/bash -ex

export DD_TRACE_CLI_ENABLED=false

# Apache in the appsec container serves /var/www/public, but the shared
# WordPress tree is laid at /var/www root (no public/ subdir). Symlink
# public -> . so Apache finds the files without materializing a second copy;
# this lets edits in the host checkout be reflected immediately through the
# overlay mount.
ln -sfn . /var/www/public

# Download WP-CLI for use by WordPressTests.groovy's @BeforeAll install step.
curl -sf https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp
chmod +x /usr/local/bin/wp
