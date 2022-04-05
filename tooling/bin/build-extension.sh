#!/usr/bin/env sh

set -euo pipefail
IFS=$'\n\t'

php_variant=${1}    # E.g. nts, debug, zts

php_major_dot_minor=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;")

target_php_version="${php_major_dot_minor}"
if [ "${php_variant}" = "nts" ]; then
    target_php_version="${target_php_version}"
elif [ "${php_variant}" = "debug" ]; then
    target_php_version="${target_php_version}-debug"
elif [ "${php_variant}" = "zts" ]; then
    target_php_version="${target_php_version}-zts"
else
    echo "Unknown PHP variant. Accepted values are 'nts', 'debug', 'zts'"
    exit 1
fi

# Building
make clean
# TODO: add back -Werror
bash -c 'source scl_source enable devtoolset-7; \
    set -eux; \
    make -j all ECHO_ARG="-e" CFLAGS="-std=gnu11 -O2 -g -Wall -Wextra"'
