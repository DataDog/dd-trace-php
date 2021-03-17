#!/bin/bash -xe

switch_php ${PHP_MAJOR_MINOR}

# Disabling xdebug as tests can fail with it (regardless of tracer installed)
sed -i 's/^zend_extension/;zend_extension/' /etc/php/${PHP_MAJOR_MINOR}/mods-available/xdebug.ini

# Cloning guzzle
git clone --depth 1 --branch ${GUZZLE_VERSION_TAG} https://github.com/guzzle/guzzle.git .


composer install

# Applying expected failures
GUZZLE_MAJOR=${GUZZLE_VERSION_TAG%.*.*}
if [[ -f "/scripts/expected-failures-${GUZZLE_MAJOR}.sh" ]]; then
    bash /scripts/expected-failures-${GUZZLE_MAJOR}.sh
fi
