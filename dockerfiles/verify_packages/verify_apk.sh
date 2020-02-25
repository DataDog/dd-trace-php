#!/bin/sh
apk add /build_src/build/packages/*.apk --allow-untrusted --no-cache

# We attempt in this order the following binary names:
#    1. php
#    2. php7 (alpine intall php 7.x from main repo to this binary)
#    3. php5 (alpine intall php 5.x from main repo to this binary)
DD_TRACE_PHP_BIN=$(command -v php)
if [[ -z "$DD_TRACE_PHP_BIN" ]]; then
    DD_TRACE_PHP_BIN=$(command -v php7)
fi
if [[ -z "$DD_TRACE_PHP_BIN" ]]; then
    DD_TRACE_PHP_BIN=$(command -v php5)
fi

# -q causes grep to exit 1 if string not found
${DD_TRACE_PHP_BIN} -m | grep -q ddtrace
