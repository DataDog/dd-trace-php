#!/usr/bin/env bash

set -e

apt update

apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt install -y \
    php7.3-cli \
    php7.3-curl \
    php7.3-fpm \
    php7.3-mbstring \
    php7.3-mysql \
    php7.3-opcache \
    php7.3-xml \
    php7.3-zip

rm -rf /var/lib/apt/lists/*

# remove unused php-fpm files that will be provided from outside
rm -rf \
    /etc/php/7.3/fpm/php-fpm.conf \
    /etc/php/7.3/fpm/pool.d
