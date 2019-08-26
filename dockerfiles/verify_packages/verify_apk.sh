#!/bin/sh
set -xe

apk add /build_src/build/packages/*.apk --allow-untrusted
php -m | grep ddtrace
php -r 'echo phpversion("ddtrace") . PHP_EOL;'
export DD_TRACE_CLI_ENABLED=true
php -r 'echo "smoke test" . PHP_EOL;'

if [ ! "$IGNORE_REQUEST_INIT_HOOK_CHECK" = true ]; then
    php -r 'echo (DDTrace\Bridge\dd_tracing_enabled() ? "TRUE" : "FALSE") . PHP_EOL;'
fi
