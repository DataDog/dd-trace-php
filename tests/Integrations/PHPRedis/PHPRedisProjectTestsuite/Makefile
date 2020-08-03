REDIS_HOST := redis_integration
INIT_HOOK := ../../../../bridge/dd_wrap_autoloader.php

assert_extension_enabled:
	DD_TRACE_CLI_ENABLED=true php -d ddtrace.request_init_hook="$(INIT_HOOK)" ../../../assert_extension_enabled.php

test_redis_3: assert_extension_enabled
	DD_TRACE_CLI_ENABLED=true php -d ddtrace.request_init_hook="$(INIT_HOOK)" 3.x/TestRedis.php --host $(REDIS_HOST) --class Redis

test_redis_array_3: assert_extension_enabled
	DD_TRACE_CLI_ENABLED=true php -d ddtrace.request_init_hook="$(INIT_HOOK)" 3.x/TestRedis.php --host $(REDIS_HOST) --class RedisArray
