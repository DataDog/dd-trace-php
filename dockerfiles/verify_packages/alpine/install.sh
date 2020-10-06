#!/usr/bin/env sh

set -xe

# Installing php
if [[ ! -z "${PHP_PACKAGE}" ]]; then
    apk add --no-cache ${PHP_PACKAGE}
fi

# Installing dd-trace-php
apk add --no-cache /build/packages/*.apk --allow-untrusted

echo "Tracer installation completed successfully"

# Installinh bash for verification scripts AFTER tracer (so we do not interfere with packages)
apk add --no-cache bash
