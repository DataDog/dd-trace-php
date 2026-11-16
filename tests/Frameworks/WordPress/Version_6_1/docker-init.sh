#!/bin/bash -ex

export DD_TRACE_CLI_ENABLED=false

# Apache in the appsec container serves /var/www/public, but the shared
# WordPress tree is laid at /var/www root (no public/ subdir). Symlink
# public -> . so Apache finds the files without materializing a second copy;
# this lets edits in the host checkout be reflected immediately through the
# overlay mount.
ln -sfn . /var/www/public

# Download WP-CLI for use by WordPress61Tests.groovy's @BeforeAll install step.
WP_CLI_VERSION=2.12.0
MIRROR="${GITHUB_RELEASES_MIRROR:-https://github.com}"
curl -Lsf "${MIRROR}/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" -o /usr/local/bin/wp
chmod +x /usr/local/bin/wp

# Point wp-config.php at the MySQL container. The test class declares it as a
# dependency of this container, so it is already up and reachable through its
# network alias. Rewrite whatever DB_HOST holds: on local reruns the overlay
# volume is reused and wp-config.php still has the previous run's address.
MYSQL_IP=$(getent hosts mysql-integration | awk '{ print $1; exit }')
if [[ -z $MYSQL_IP ]]; then
    echo "Could not resolve mysql-integration" >&2
    exit 1
fi
sed -i -E "s/(define\(\s*'DB_HOST'\s*,\s*)'[^']*'/\1'${MYSQL_IP}'/" /var/www/wp-config.php
