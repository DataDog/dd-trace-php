#!/bin/sh

set -xe

PHP_PACKAGE=$1

# Installing
sh /install.sh ${PHP_PACKAGE}

echo "OK"
