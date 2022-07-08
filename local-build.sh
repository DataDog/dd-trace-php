#!/usr/bin/env bash

set -e

rm -rf extensions
mkdir -p extensions
rm -rf build/packages
mkdir -p build/packages

function build_version() {
    PHP_VERSION=$1
    PHP_API=$2
    SUFFIX=${3:-''}
    rm -rf tmp
    docker run --rm -w /var/app -v $(pwd):/var/app circleci/php:${PHP_VERSION}${SUFFIX} make all CFLAGS="-O2 -std=c99 -Wall -Wextra -Wextra"
    docker run --rm -w /var/app -v $(pwd):/var/app circleci/php:${PHP_VERSION}${SUFFIX} cp tmp/build_extension/.libs/ddtrace.so extensions/ddtrace-${PHP_API}${SUFFIX}.so
}

build_version 7.0 20151012
build_version 7.0 20151012 '-zts'
build_version 7.1 20160303
build_version 7.1 20160303 '-zts'
build_version 7.2 20170718
build_version 7.2 20170718 '-zts'

docker run --rm -v $(pwd):/var/app datadog/docker-library:php_toolbox make packages
