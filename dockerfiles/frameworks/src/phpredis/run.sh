#!/bin/bash -xe
export TEST_PHP_ARGS="-dmemory_limit=-1 -dextension=/usr/lib/php/20180731/json.so -dextension=modules/redis.so"

if [[ -z "$NO_DDTRACE" ]]; then
    export DD_TRACE_CLI_ENABLED=true
    export TEST_PHP_ARGS="${TEST_PHP_ARGS} -dextension=/opt/datadog-php/extensions/ddtrace-20180731.so -dddtrace.request_init_hook=/opt/datadog-php/dd-trace-sources/bridge/dd_wrap_autoloader.php"
fi

php -dextension=modules/redis.so tests/TestRedis.php --class RedisCluster --host redis_cluster
php -dextension=modules/redis.so tests/TestRedis.php --class Redis --host redis
