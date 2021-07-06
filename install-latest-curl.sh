#!/usr/bin/env bash

set -e

CURL_MAJOR=7
CURL_MINOR=77
CURL_PATCH=0
CURL_VERSION=${CURL_MAJOR}.${CURL_MINOR}.${CURL_PATCH}

curl -L -O https://github.com/curl/curl/releases/download/curl-${CURL_MAJOR}_${CURL_MINOR}_${CURL_PATCH}/curl-${CURL_VERSION}.tar.gz
tar -xf curl-${CURL_VERSION}.tar.gz
cd curl-${CURL_VERSION}

# Followed this guide to build: https://curl.se/docs/install.html#unix
./configure --with-openssl
make
sudo make install

# Verifying via pkg-config that pkg-config actually read the latest version of cURL
actual_libcurl=$(pkg-config --modversion libcurl)
echo "Curl versions: expected=${CURL_VERSION}) actual=${actual_libcurl}"

# # PHP-8.0 Rebuilding php (from dockerfiles/ci/buster/php-7.3/Dockerfile)
# mkdir -p /tmp/build-php && cd /tmp/build-php
# /usr/local/src/php/configure \
#     --disable-phpdbg \
#     --enable-option-checking=fatal \
#     --enable-cgi \
#     --enable-embed \
#     --enable-fpm \
#     --enable-ftp \
#     --enable-mbstring \
#     --enable-opcache \
#     --enable-pcntl \
#     --enable-sockets \
#     --with-curl \
#     --with-fpm-user=www-data \
#     --with-fpm-group=www-data \
#     --with-libedit \
#     --with-mhash \
#     --with-mysqli=mysqlnd \
#     --with-openssl \
#     --with-pdo-mysql=mysqlnd \
#     --with-pdo-pgsql \
#     --with-pdo-sqlite \
#     --with-pear \
#     --with-readline \
#     --with-zlib \
#     --enable-debug \
#     --prefix=${PHP_INSTALL_DIR_DEBUG_NTS} \
#     --with-config-file-path=${PHP_INSTALL_DIR_DEBUG_NTS} \
#     --with-config-file-scan-dir=${PHP_INSTALL_DIR_DEBUG_NTS}/conf.d

# PHP-7.3 Rebuilding php (from dockerfiles/ci/buster/php-7.3/Dockerfile)
mkdir -p /tmp/build-php && cd /tmp/build-php
/usr/local/src/php/configure \
    --disable-phpdbg \
    --enable-option-checking=fatal \
    --enable-cgi \
    --enable-embed \
    --enable-fpm \
    --enable-ftp \
    --enable-mbstring \
    --enable-opcache \
    --enable-pcntl \
    --enable-sockets \
    --enable-zip \
    --with-curl \
    --with-fpm-user=www-data \
    --with-fpm-group=www-data \
    --with-libedit \
    --with-mhash \
    --with-mysqli=mysqlnd \
    --with-openssl \
    --with-pdo-mysql=mysqlnd \
    --with-pdo-pgsql \
    --with-pdo-sqlite \
    --with-pear \
    --with-readline \
    --with-zlib \
    --enable-debug \
    --prefix=${PHP_INSTALL_DIR_DEBUG_NTS} \
    --with-config-file-path=${PHP_INSTALL_DIR_DEBUG_NTS} \
    --with-config-file-scan-dir=${PHP_INSTALL_DIR_DEBUG_NTS}/conf.d

make -j "4"
# make -j "$((`nproc`+1))"
make install
mkdir -p ${PHP_INSTALL_DIR_DEBUG_NTS}/conf.d

echo "PHP is using the following version of curl now: " $(php -i | grep 'cURL')
