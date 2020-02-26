#!/bin/sh
set -xe
dpkg -i build/packages/*.deb
php -m | grep -q ddtrace
