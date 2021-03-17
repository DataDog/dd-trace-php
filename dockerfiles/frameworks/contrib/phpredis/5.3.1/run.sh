#!/bin/bash -xe

switch_php 7.3
DD_TRACE_CLI_ENABLED=true php ./tests/TestRedis.php --host ${REDIS_HOST} --class Redis
DD_TRACE_CLI_ENABLED=true php ./tests/TestRedis.php --host ${REDIS_HOST} --class RedisArray
