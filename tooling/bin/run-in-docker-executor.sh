#!/usr/bin/env sh

set -eux

php_variant=$1
php_major_minor=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

switch-php $php_variant
php -v

make sudo debug install install_ini BUILD_DIR=tmp/build_extension

shift
$(@)
