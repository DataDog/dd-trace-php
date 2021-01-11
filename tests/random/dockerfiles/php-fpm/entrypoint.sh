#!/usr/bin/env sh

set -euo

if [ ! -z "${TRACER_VERSION}" ]; then
    dpkg -i /tracer-versions/datadog-php-tracer_${TRACER_VERSION}_amd64.deb
fi

composer update

# Enabling core dumps
echo '/coredumps/core' > /proc/sys/kernel/core_pattern

php-fpm
