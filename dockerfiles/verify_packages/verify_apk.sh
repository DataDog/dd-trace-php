#!/bin/sh
apk add /build_src/build/packages/*.apk --allow-untrusted --no-cache
php -m | grep ddtrace
