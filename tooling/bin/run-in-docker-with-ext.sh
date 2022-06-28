#!/usr/bin/env sh

set -eux

php_variant=${PHP_VARIANT:-nts}
php_major_minor=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

echo "Running with PHP variant: $php_variant"
switch-php $php_variant
php -v

make clean sudo debug install install_ini BUILD_DIR=tmp/build_extension

${@}
