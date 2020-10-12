#!/usr/bin/env sh

set -e

# Installing php
if [ ! -z "${PHP_PACKAGE}" ]; then
    apk add --no-cache ${PHP_PACKAGE}
fi

# Installing dd-trace-php
apk add --no-cache $(pwd)/build/packages/*.apk --allow-untrusted

echo "Tracer installation completed successfully"
