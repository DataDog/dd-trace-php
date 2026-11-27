#!/bin/sh
# Unless explicitly stated otherwise all files in this repository are
# dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
#
# This product includes software developed at Datadog
# (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

# Manual check (too slow for CI: builds ext/curl from the PHP sources): the
# libcurl linkage detection on a real curl.so with libcurl.a linked in, alone
# and with a second, dynamic libcurl preloaded (on musl, the preload then
# serves curl.so's PLT calls although curl.so carries its own libcurl).
# Expected verdict in both cases: off, "defines curl_global_init itself".
#
# Run from the repository root (any arch):
#   docker run --rm -v "$PWD:/src:ro" alpine:3.22 \
#     sh /src/appsec/tests/curl_linkage/manual_static_curl_so.sh

set -e
HERE=$(cd "$(dirname "$0")" && pwd)
# libcurl.a; its own dependencies are linked dynamically
apk add -q php83 php83-dev build-base curl-dev curl-static openssl-dev \
  nghttp2-dev zlib-dev brotli-dev zstd-dev c-ares-dev libidn2-dev libpsl-dev \
  libunistring-dev >/dev/null

# PHP_TARBALL: a local php-8.3.x.tar.xz instead of downloading one
mkdir -p /b && cd /b
if [ -n "${PHP_TARBALL:-}" ]; then
  cp "$PHP_TARBALL" php.tar.xz
else
  wget -q -O php.tar.xz \
    "https://www.php.net/distributions/php-$(php83 -r 'echo PHP_VERSION;').tar.xz"
fi
tar xJf php.tar.xz && cd php-*/ext/curl
phpize83 >/dev/null
DEPS=$(pkg-config --static --libs libcurl | sed 's/-lcurl//')
CURL_CFLAGS="$(pkg-config --cflags libcurl)" CURL_LIBS="/usr/lib/libcurl.a $DEPS" \
  ./configure --with-php-config=php-config83 >/dev/null
make -j"$(nproc)" >/tmp/build.log 2>&1
cc -shared -fPIC -Wl,-z,now -o /b/curl_static.so .libs/*.o /usr/lib/libcurl.a $DEPS
echo "== /b/curl_static.so"
readelf -d /b/curl_static.so | grep NEEDED | grep -i curl || echo "(no DT_NEEDED on libcurl)"
nm -D --defined-only /b/curl_static.so | grep -E ' curl_global_init$'

cc -O1 -g -shared -fPIC $(php-config83 --includes) -o /b/harness.so \
  "$HERE/php_harness.c" "$HERE/../../src/extension/downstream/curl_linkage_detect.c"
RUN='echo curl_linkage_detect(), "\n";
$c = curl_init("file:///etc/hostname");
curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
echo "curl_exec works: ", var_export(curl_exec($c) !== false, 1), "\n";'

echo "== static libcurl in curl.so"
php83 -n -d extension=/b/curl_static.so -d extension=/b/harness.so -r "$RUN"
echo "== same, LD_PRELOAD=/usr/lib/libcurl.so.4"
LD_PRELOAD=/usr/lib/libcurl.so.4 \
  php83 -n -d extension=/b/curl_static.so -d extension=/b/harness.so -r "$RUN"
echo "== distro curl.so (dynamic libcurl), for comparison"
apk add -q php83-curl >/dev/null
php83 -n -d extension=curl -d extension=/b/harness.so -r "$RUN"
