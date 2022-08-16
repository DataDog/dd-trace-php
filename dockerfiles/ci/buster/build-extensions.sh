#!/bin/bash
set -eux

PHP_VERSION_ID=$(php -r 'echo PHP_MAJOR_VERSION . PHP_MINOR_VERSION;')

XDEBUG_VERSIONS=(-3.1.2)
if [[ $PHP_VERSION_ID -le 70 ]]; then
  XDEBUG_VERSIONS=(-2.7.2)
elif [[ $PHP_VERSION_ID -le 74 ]]; then
  XDEBUG_VERSIONS=(-2.9.2 -2.9.5)
elif [[ $PHP_VERSION_ID -le 80 ]]; then
  XDEBUG_VERSIONS=(-3.0.0)
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

pecl channel-update pecl.php.net;
iniDir=$(php -i | awk -F"=> " '/Scan this dir for additional .ini files/ {print $2}');

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
