#!/bin/bash

set -eux

frankenphpTarGzUrl=https://github.com/dunglas/frankenphp/archive/refs/tags/v1.1.2.tar.gz
FRANKENPHP_SRC_DIR=/usr/local/src/frankenphp

mkdir -p $FRANKENPHP_SRC_DIR

curl -Lo /tmp/frankenphp.tar.gz ${frankenphpTarGzUrl}
tar xf /tmp/frankenphp.tar.gz -C ${FRANKENPHP_SRC_DIR} --strip-components=1
rm -f /tmp/frankenphp.tar.gz

cd ${FRANKENPHP_SRC_DIR}
cd caddy/frankenphp

CGO_CFLAGS=$(php-config --includes) CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" go build

mv frankenphp $(readlink $(which frankenphp))
