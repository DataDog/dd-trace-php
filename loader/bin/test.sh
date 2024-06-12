#!/usr/bin/env bash
set -eo pipefail

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

printf "Running PHPT tests\n"
php -n run-tests.php -q -p $(which php) -n -d zend_extension=${PWD}/modules/dd_library_loader.so --show-diff

printf "\nRunning functional tests\n\n"
failure=0
for file in $(find tests/functional/ -name 'test_*.php'); do
    echo "${file}"
    php -n ${file} | sed 's/^/     /' || failure=1
done

if [[ $failure -eq 1 ]]; then
    exit 1
fi
