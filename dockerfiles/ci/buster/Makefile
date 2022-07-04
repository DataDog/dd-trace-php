ARCHITECTURE := $(shell uname -m)
DOCKER_IMAGE_PREFIX := datadog/dd-trace-ci:php-
DOCKER_IMAGE_SUFFIX := _buster
DOCKER_IMAGE_SHARED_SUFFIX := -shared-ext

build.x86_64: \
	build.8.1 \
	build.8.0 \
	build.shared.8.0 \
	build.7.4 \
	build.shared.7.4 \
	build.7.3 \
	build.7.2 \
	build.7.1 \
	build.7.0

build.aarc64: \
	build.7.4 \
	build.shared.7.4

build.base:
	docker build -t datadog/dd-trace-ci:buster .

build.%: build.base
	set -eux; \
	source "php-$(*)/env"; \
	docker build \
		--build-arg phpVersion=$(*) \
		--build-arg phpTarGzUrl=$$phpTarGzUrl \
		--build-arg phpSha256Hash=$$phpSha256Hash \
		-t $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX)-$(ARCHITECTURE) "php-$(*)"

build.shared.%: build.base
	set -eux; \
	source "php-$(*)/env"; \
	docker build \
		--file Dockerfile_shared_ext \
		--build-arg phpVersion=$(*) \
		--build-arg phpTarGzUrl=$$phpTarGzUrl \
		--build-arg phpSha256Hash=$$phpSha256Hash \
		-t $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX)-$(ARCHITECTURE) "php-$(*)"

push.%: build.%
	docker push $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX)-$(ARCHITECTURE)

push.shared.%: build.%
	docker push $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX)-$(ARCHITECTURE)
