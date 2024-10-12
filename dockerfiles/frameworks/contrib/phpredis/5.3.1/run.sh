#!/bin/bash -xe

switch_php 7.3
php ./tests/TestRedis.php --host ${REDIS_HOST} --class Redis
php ./tests/TestRedis.php --host ${REDIS_HOST} --class RedisArray
