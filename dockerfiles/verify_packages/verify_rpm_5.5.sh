#!/bin/bash

module load php
export DD_TRACE_PHP_BIN=$(which php)

rpm -Uvh /build_src/build/packages/*.rpm

php -m | grep ddtrace
