#!/bin/sh
set -xe
dpkg -i build/packages/*.deb
php -m | grep ddtrace
php -r 'echo phpversion("ddtrace") . PHP_EOL;'
