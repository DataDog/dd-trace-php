#!/bin/bash -xe
PHP_VERSION=${PHP_VERSION:=$1}
SO_SUFFIX=${SO_SUFFIX:=$2}
CFLAGS=${CFLAGS:="-std=gnu11 -O2 -g -Wall -Wextra"}

mkdir -p extensions
switch-php $PHP_VERSION 
make all ECHO_ARG="-e" CFLAGS="${CFLAGS}"
cp tmp/build_extension/.libs/ddtrace.so extensions/ddtrace-${SO_SUFFIX}.so 
