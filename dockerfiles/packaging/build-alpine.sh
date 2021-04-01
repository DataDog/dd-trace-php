#!/usr/bin/env sh

set -ex

PHP_VERSION=${PHP_VERSION:=$1}
SO_SUFFIX=${SO_SUFFIX:=$2}
CFLAGS=${CFLAGS:="-std=gnu11 -O2 -g -Wall -Wextra -Werror"}

echo "Building alpine PHP ${PHP_VERSION}/${SO_SUFFIX}"

apk add --no-cache \
    autoconf \
    bash \
    g++ \
    gcc \
    libexecinfo-dev \
    make \

mkdir -p extensions
make all ECHO_ARG="-e" CFLAGS="${CFLAGS}"

cp tmp/build_extension/.libs/ddtrace.so extensions/ddtrace-${SO_SUFFIX}-alpine.so
