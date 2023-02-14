#!/bin/bash
set -eux

SHARED_BUILD=$(if php -i | grep -q =shared; then echo 1; else echo 0; fi)
PHP_VERSION_ID=$(php -r 'echo PHP_MAJOR_VERSION . PHP_MINOR_VERSION;')

XDEBUG_VERSIONS=(-3.1.2)
if [[ $PHP_VERSION_ID -le 70 ]]; then
  XDEBUG_VERSIONS=(-2.7.2)
elif [[ $PHP_VERSION_ID -le 74 ]]; then
  XDEBUG_VERSIONS=(-2.9.2 -2.9.5)
elif [[ $PHP_VERSION_ID -le 80 ]]; then
  XDEBUG_VERSIONS=(-3.0.0)
elif [[ $PHP_VERSION_ID -le 81 ]]; then
  XDEBUG_VERSIONS=(-3.1.0)
else
  XDEBUG_VERSIONS=(-3.2.0RC1)
fi

MONGODB_VERSION=
if [[ $PHP_VERSION_ID -le 70 ]]; then
  MONGODB_VERSION=-1.9.2
elif [[ $PHP_VERSION_ID -le 71 ]]; then
  MONGODB_VERSION=-1.11.1
fi

AST_VERSION=
if [[ $PHP_VERSION_ID -le 71 ]]; then
  AST_VERSION=-1.0.16
fi

HOST_ARCH=$(if [[ $(file $(readlink -f $(which php))) == *aarch64* ]]; then echo "aarch64"; else echo "x86_64"; fi)

export PKG_CONFIG=/usr/bin/$HOST_ARCH-linux-gnu-pkg-config
export CC=$HOST_ARCH-linux-gnu-gcc

iniDir=$(php -i | awk -F"=> " '/Scan this dir for additional .ini files/ {print $2}');

if [[ $SHARED_BUILD -ne 0 ]]; then
  # Build curl versions
  CURL_VERSIONS="7.72.0 7.77.0"
  for curlVer in ${CURL_VERSIONS}; do
    echo "Build curl ${curlVer}..."
    cd /tmp
    curl -L -o curl.tar.gz https://curl.se/download/curl-${curlVer}.tar.gz
    tar -xf curl.tar.gz && rm curl.tar.gz
    cd curl-${curlVer}
    ./configure --with-openssl --prefix=/opt/curl/${curlVer}
    make
    make install
  done

  # Build core extensions as shared libraries.
  # We intentionally do not run 'make install' here so that we can test the
  # scenario where headers are not installed for the shared library.
  # ext/curl
  cd ${PHP_SRC_DIR}/ext/curl
  phpize
  ./configure
  make
  mv ./modules/*.so $(php-config --extension-dir)
  make clean
  
  for curlVer in ${CURL_VERSIONS}; do
    PKG_CONFIG_PATH=/opt/curl/${curlVer}/lib/pkgconfig/
    ./configure
    make
    mv ./modules/curl.so $(php-config --extension-dir)/curl-${curlVer}.so
    make clean
  done
  phpize --clean

  # ext/pdo
  cd ${PHP_SRC_DIR}/ext/pdo
  phpize
  ./configure
  make
  mv ./modules/*.so $(php-config --extension-dir)
  make clean;
  phpize --clean

  # TODO Add ext/pdo_mysql, ext/pdo_pgsql, and ext/pdo_sqlite
else
  pecl channel-update pecl.php.net;

  yes '' | pecl install apcu; echo "extension=apcu.so" >> ${iniDir}/apcu.ini;
  pecl install ast$AST_VERSION; echo "extension=ast.so" >> ${iniDir}/ast.ini;
  if [[ $PHP_VERSION_ID -ge 71 && $PHP_VERSION_ID -le 80 ]]; then
    yes '' | pecl install mcrypt$(if [[ $PHP_VERSION_ID -le 71 ]]; then echo -1.0.0; fi); echo "extension=mcrypt.so" >> ${iniDir}/mcrypt.ini;
  fi
  yes 'no' | pecl install memcached; echo "extension=memcached.so" >> ${iniDir}/memcached.ini;
  pecl install mongodb$MONGODB_VERSION; echo "extension=mongodb.so" >> ${iniDir}/mongodb.ini;
  pecl install redis; echo "extension=redis.so" >> ${iniDir}/redis.ini;
  # Xdebug is disabled by default
  for VERSION in "${XDEBUG_VERSIONS[@]}"; do
    pecl install xdebug$VERSION;
    cd $(php-config --extension-dir);
    mv xdebug.so xdebug$VERSION.so;
  done
  echo "zend_extension=opcache.so" >> ${iniDir}/../php-apache2handler.ini;
fi
