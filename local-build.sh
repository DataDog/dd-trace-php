#!/usr/bin/env bash

set -e

rm -rf extensions
mkdir -p extensions
rm -rf build/packages
mkdir -p build/packages

function build_version() {
    PHP_VERSION=$1
    SUFFIX=${2:-''}
    rm -rf tmp
    docker run --rm -w /var/app -v $(pwd):/var/app circleci/php:${PHP_VERSION}${SUFFIX} make all CFLAGS="-O2 -std=c99 -Wall -Wextra -Wextra"
    docker run --rm -w /var/app -v $(pwd):/var/app circleci/php:${PHP_VERSION}${SUFFIX} cp tmp/build_extension/.libs/ddtrace.so extensions/ddtrace-${PHP_VERSION}${SUFFIX}.so
}

function build_version_54() {
    PHP_VERSION=5.4
    rm -rf tmp
    docker run --rm -w /var/app -v $(pwd):/var/app datadog/docker-library:ddtrace_centos_6_php_5_4 make all CFLAGS="-O2 -std=c99 -Wall -Wextra -Wextra" ECHO_ARG="-e"
    docker run --rm -w /var/app -v $(pwd):/var/app datadog/docker-library:ddtrace_centos_6_php_5_4 cp tmp/build_extension/.libs/ddtrace.so extensions/ddtrace-${PHP_VERSION}.so
}

build_version_54
build_version 5.6
build_version 5.6 '-zts'
build_version 7.0
build_version 7.0 '-zts'
build_version 7.1
build_version 7.1 '-zts'
build_version 7.2
build_version 7.2 '-zts'

docker run --rm -v $(pwd):/var/app datadog/docker-library:php_toolbox make packages
