Q := @
REDIS_IMAGE := datadog/dd-trace-ci:php-redis-5.0
REQUEST_REPLAYER_IMAGE := datadog/dd-trace-ci:php-request-replayer-1.0

redis_build:
	$(Q) docker build -t $(REDIS_IMAGE) redis

redis_publish: redis_build
	$(Q) docker push $(REDIS_IMAGE)

request-replayer_build:
	$(Q) docker build -t $(REQUEST_REPLAYER_IMAGE) request-replayer

request-replayer_publish: request-replayer_build
	$(Q) docker push $(REQUEST_REPLAYER_IMAGE)
