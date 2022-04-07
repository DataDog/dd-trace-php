#!/usr/bin/env sh

set -euo pipefail
IFS=$'\n\t'

echo "Hello everyone"
exit

php_variant=$1
php_major_minor=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

switch-php $php_variant

php -v
