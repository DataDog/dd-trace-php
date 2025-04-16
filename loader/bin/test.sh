#!/usr/bin/env bash
set -euo pipefail

function _exit {
    if [[ $? -eq 0 ]]; then
        printf "\nSUCCESS\n"
        exit 0
    else
        printf "\nFAILURE\n"
        exit 1
    fi
}
trap _exit EXIT

if [[ ! -f run-tests.php ]]; then
    phpize
fi

if [[ -z "${DD_LOADER_PACKAGE_PATH:-}" ]]; then
    DEFAULT="/home/circleci/app/dd-library-php-ssi"
    echo "Note: env var 'DD_LOADER_PACKAGE_PATH' is required but not defined, using the default value (${DEFAULT})"
    export DD_LOADER_PACKAGE_PATH="${DEFAULT}"
fi

printf "PHP version\n\n"
php -n -v

printf "Test load loader\n\n"
DD_TRACE_DEBUG=1 php -n -d zend_extension=${PWD}/modules/dd_library_loader.so -v

printf "\nRunning PHPT tests\n"
# point extension_dir into nirvana to avoid issues with dl()
php -n run-tests.php -q -p $(which php) -n -d extension_dir=/dev/shm/ -d zend_extension=${PWD}/modules/dd_library_loader.so --show-diff

printf "\nRunning functional tests\n\n"
failure=0
for file in $(find tests/functional/ -name 'test_*.php'); do
    echo "${file}"
    php -n ${file} | sed 's/^/     /' || failure=1
done

if [[ $failure -eq 1 ]]; then
    exit 1
fi
