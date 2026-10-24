#!/bin/bash -ex

export DD_TRACE_CLI_ENABLED=false

# Apache in the appsec container serves /var/www/public, but the shared
# WordPress tree is laid at /var/www root (no public/ subdir). Symlink
# public -> . so Apache finds the files without materializing a second copy;
# this lets edits in the host checkout be reflected immediately through the
# overlay mount.
ln -sfn . /var/www/public

# Appsec-only mu-plugins (route-normalization fixtures). Kept out of the
# shared WordPress tree so the tracer's web_wordpress tests do not load
# them and their extra callback spans do not change tracer snapshots.
mkdir -p /var/www/wp-content/mu-plugins
cp /project/appsec/tests/integration/src/test/resources/wordpress-mu-plugins/*.php \
    /var/www/wp-content/mu-plugins/

# Download WP-CLI for use by WordPress61Tests.groovy's @BeforeAll install step.
WP_CLI_VERSION=2.12.0
MIRROR="${GITHUB_RELEASES_MIRROR:-https://github.com}"
curl -Lsf "${MIRROR}/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" -o /usr/local/bin/wp
chmod +x /usr/local/bin/wp
