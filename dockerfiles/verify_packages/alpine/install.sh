#!/usr/bin/env sh

set -xe

# Installing php
if [[ ! -z "${PHP_PACKAGE}" ]]; then
    apk add --no-cache ${PHP_PACKAGE}
fi

# Installing dd-trace-php
apk add --no-cache /dd-trace-php.apk --allow-untrusted

echo "Installation completed succesfully"
