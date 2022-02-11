#!/usr/bin/env sh

set -ex

PHP_VERSION=${PHP_VERSION:=$1}
SO_SUFFIX=${SO_SUFFIX:=$2}
CFLAGS=${CFLAGS:="-std=gnu11 -O2 -g -Wall -Wextra -Werror"}

echo "Building alpine PHP ${PHP_VERSION}/${SO_SUFFIX}"

mkdir -p extensions
make all ECHO_ARG="-e" CFLAGS="${CFLAGS}" BUILD_DIR="."

cp .libs/ddtrace.so extensions/ddtrace-${SO_SUFFIX}-alpine.so
