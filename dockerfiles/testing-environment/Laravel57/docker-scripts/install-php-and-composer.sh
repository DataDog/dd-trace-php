#!/usr/bin/env bash

set -e

apt update

apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php

# Enabling ondrej debug symbols repo
sed -i 's/deb \(.\+\)/deb \1 main\/debug/g' /etc/apt/sources.list.d/ondrej-ubuntu-php-bionic.list

# Installing PHP
apt update && apt install -y \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-cli-dbgsym \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-curl-dbgsym \
    php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-fpm-dbgsym \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-mbstring-dbgsym \
    php${PHP_VERSION}-mysql \
    php${PHP_VERSION}-mysql-dbgsym \
    php${PHP_VERSION}-opcache \
    php${PHP_VERSION}-opcache-dbgsym \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-xml-dbgsym \
    php${PHP_VERSION}-zip \
    php${PHP_VERSION}-zip-dbgsym
# remove unused php-fpm files that will be provided from outside
rm -rf \
    /etc/php/${PHP_VERSION}/fpm/php-fpm.conf \
    /etc/php/${PHP_VERSION}/fpm/pool.d

update-alternatives --install /usr/bin/php-fpm php-fpm /usr/sbin/php-fpm${PHP_VERSION} 1

# Installing composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --quiet --filename=composer --install-dir=/usr/local/bin/


rm -rf /var/lib/apt/lists/*
