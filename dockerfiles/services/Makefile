Q := @
REDIS_IMAGE := datadog/dd-trace-ci:php-redis-5.0

redis_build:
	$(Q) docker build -t $(REDIS_IMAGE) redis

redis_publish: redis_build
	$(Q) docker push $(REDIS_IMAGE)

# It requires buildx to be able to build cross-architecture images
request-replayer_push:
	docker buildx build --platform=linux/arm64,linux/amd64 -t datadog/dd-trace-ci:php-request-replayer-2.0 ./request-replayer --push

# It requires buildx to be able to build cross-architecture images
mysql_push:
	docker buildx build --platform=linux/arm64,linux/amd64 -t datadog/dd-trace-ci:php-mysql-5.7 ./mysql --push
