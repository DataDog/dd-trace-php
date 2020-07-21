REDIS_HOST := redis_integration
INIT_HOOK := /home/circleci/app/bridge/dd_wrap_autoloader.php

test_redis_3:
	DD_TRACE_CLI_ENABLED=true php -d ddtrace.request_init_hook="$(INIT_HOOK)" 3.x/TestRedis.php --host $(REDIS_HOST) --class Redis

test_redis_array_3:
	DD_TRACE_CLI_ENABLED=true php -d ddtrace.request_init_hook="$(INIT_HOOK)" 3.x/TestRedis.php --host $(REDIS_HOST) --class RedisArray

test_redis_cluster_3:
	DD_TRACE_CLI_ENABLED=true php -d ddtrace.request_init_hook="$(INIT_HOOK)" 3.x/TestRedis.php --host $(REDIS_HOST) --class RedisCluster --test testPing

prepare:
	php TestRedis.php --host $(REDIS_HOST) --class redis
