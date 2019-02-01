#!/bin/sh
apk add /build_src/build/packages/*.apk --allow-untrusted
php -m | grep ddtrace
