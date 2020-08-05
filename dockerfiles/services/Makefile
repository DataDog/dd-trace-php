Q := @
REDIS_IMAGE := datadog/dd-trace-ci:php-redis-4.0

redis_build:
	$(Q) docker build -t $(REDIS_IMAGE) redis

redis_publish: redis_build
	$(Q) docker push $(REDIS_IMAGE)
