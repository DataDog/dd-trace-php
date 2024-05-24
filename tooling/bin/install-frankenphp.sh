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

if ldd $(which php) 2>/dev/null | grep -q libasan; then
  ASAN="-fsanitize=address"
else
  ASAN=""
fi

CGO_CFLAGS="$(php-config --includes) $ASAN" CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs) $ASAN" go build

mv frankenphp $(readlink /usr/local/bin/frankenphp)
