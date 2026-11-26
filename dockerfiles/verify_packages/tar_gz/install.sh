#!/usr/bin/env sh

set -e

. "$(dirname "$0")/../apt_common.sh"

# Common installations
retry_or_tempfail apt_get update
retry_or_tempfail apt_get install -y \
    apt-transport-https \
    lsb-release \
    ca-certificates \
    curl \
    nginx \
    apache2 \
    procps \
    gnupg

add_sury_repo
retry_or_tempfail apt_get update
retry_or_tempfail apt_get install -y \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-opcache \
    libapache2-mod-php${PHP_VERSION}
    WWW_CONF=/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
    PHP_FPM_BIN=php-fpm${PHP_VERSION}

echo "PHP installation completed successfully"
