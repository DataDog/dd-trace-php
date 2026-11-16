Q := @
REDIS_IMAGE := datadog/dd-trace-ci:php-redis-5.0
MYSQL_IMAGE := datadog/dd-trace-ci:php-mysql-dev-5.6

redis_build:
	$(Q) docker build -t $(REDIS_IMAGE) redis

redis_publish: redis_build
	$(Q) docker push $(REDIS_IMAGE)

mysql_publish: redis_build
	docker buildx build --platform=linux/amd64 -t $(MYSQL_IMAGE) mysql --push

httpbin_windows_push:
	docker build -t datadog/dd-trace-ci:httpbin-windows ./windows-httpbin -f windows-httpbin/Dockerfile
	docker push datadog/dd-trace-ci:httpbin-windows
