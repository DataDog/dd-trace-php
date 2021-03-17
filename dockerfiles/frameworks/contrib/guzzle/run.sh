#!/bin/bash -e


# Cloning guzzle
git clone --depth 1 --branch ${GUZZLE_VERSION_TAG} https://github.com/guzzle/guzzle.git .


for PHP_V in ${PHP_MAJOR_MINOR_TESTED}; do
    echo "############################################################################"
    echo "# Testing Guzzle ${GUZZLE_VERSION_TAG} on PHP ${PHP_V}"
    echo "############################################################################"

    # Disabling xdebug as tests can fail with it (regardless of tracer installed)
    sed -i 's/^zend_extension/;zend_extension/' /etc/php/${PHP_V}/mods-available/xdebug.ini

    switch_php ${PHP_V}

    composer update

    # Applying expected failures
    GUZZLE_MAJOR=${GUZZLE_VERSION_TAG%.*.*}
    if [[ -f "/scripts/expected-failures-${GUZZLE_MAJOR}.sh" ]]; then
        bash /scripts/expected-failures-${GUZZLE_MAJOR}.sh
    fi

    make test
done
