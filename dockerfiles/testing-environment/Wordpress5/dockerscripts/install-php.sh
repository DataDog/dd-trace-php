#!/usr/bin/env bash

set -e

apt update

apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php

# Installing PHP
apt install -y \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-mysql \
    php${PHP_VERSION}-opcache \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-zip
# remove unused php-fpm files that will be provided from outside
rm -rf \
    /etc/php/${PHP_VERSION}/fpm/php-fpm.conf \
    /etc/php/${PHP_VERSION}/fpm/pool.d

update-alternatives --install /usr/bin/php-fpm php-fpm /usr/sbin/php-fpm${PHP_VERSION} 1

rm -rf /var/lib/apt/lists/*
