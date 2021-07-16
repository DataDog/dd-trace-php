#!/usr/bin/env sh

set -e

# Common installations
apt update
apt install -y \
    apt-transport-https \
    lsb-release \
    ca-certificates \
    curl \
    software-properties-common \
    nginx \
    apache2 \
    procps \
    gnupg

curl -sSL -o /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
sh -c 'echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list'
apt update
apt install -y \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-opcache \
    libapache2-mod-php${PHP_VERSION}
    WWW_CONF=/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
    PHP_FPM_BIN=php-fpm${PHP_VERSION}

echo "PHP installation completed successfully"
