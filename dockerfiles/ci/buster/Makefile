SCENARIOS := \
	x86_64.7.0 \
	x86_64.7.1 \
	x86_64.7.2 \
	x86_64.7.3 \
	multi.7.4 \
	shared.multi.7.4 \
	x86_64.8.0 \
	shared.x86_64.8.0 \
	x86_64.8.1
ARCHITECTURE := $(shell uname -m)
DOCKER_IMAGE_PREFIX := datadog/dd-trace-ci:php-
DOCKER_IMAGE_SUFFIX := _buster
DOCKER_IMAGE_SHARED_SUFFIX := -shared-ext

# Steps:
#  - On a x86_64 machine run `make push.x86_64`
#  - On a aarch64/arm64 machine run `make push.aarch64`
#  - On any machine run `make publish`

# `publish` expects architecture-specic images already available on docker hub. See below.
publish: manifest.push

manifest.push: $(addprefix manifest.push.,$(SCENARIOS))

manifest.create: $(addprefix manifest.create.,$(SCENARIOS))

manifest.create.x86_64.%:
	docker manifest rm $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX) || true
	docker manifest rm $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX)-x86_64 || true
	docker manifest create \
		$(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX) \
		--amend \
			$(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX)-x86_64

manifest.create.shared.x86_64.%:
	docker manifest rm $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX) || true
	docker manifest rm $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX)-x86_64 || true
	docker manifest create \
		$(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX) \
		--amend \
			$(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX)-x86_64

manifest.create.multi.%:
	docker manifest rm $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX) || true
	docker manifest rm $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX)-x86_64 || true
	docker manifest rm $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX)-aarch64 || true
	docker manifest create \
		$(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX) \
		--amend \
			$(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX)-aarch64 \
			$(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX)-x86_64

manifest.create.shared.multi.%:
	docker manifest rm $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX) || true
	docker manifest rm $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX)-x86_64 || true
	docker manifest rm $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX)-aarch64 || true
	docker manifest create \
		$(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX) \
		--amend \
			$(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX)-aarch64 \
			$(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX)-x86_64

manifest.push.x86_64.%: manifest.create.x86_64.%
	docker manifest push $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX)

manifest.push.multi.%: manifest.create.multi.%
	docker manifest push $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SUFFIX)

manifest.push.shared.x86_64.%: manifest.create.shared.x86_64.%
	docker manifest push $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX)

manifest.push.shared.multi.%: manifest.create.shared.multi.%
	docker manifest push $(DOCKER_IMAGE_PREFIX)$(*)$(DOCKER_IMAGE_SHARED_SUFFIX)

build.base:
	docker build -t datadog/dd-trace-ci:buster .

#################################################################
# x86_64 section, build this on an aarch64 machine, i.e. on AWS
#################################################################

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

push.x86_64: \
	push.8.1 \
	push.8.0 \
	push.shared.8.0 \
	push.7.4 \
	push.shared.7.4 \
	push.7.3 \
	push.7.2 \
	push.7.1 \
	push.7.0

#################################################################
# end of x86_64
#################################################################

#################################################################
# aarch64 section, build this on an aarch64 machine, i.e. on AWS
#################################################################

build.aarch64: \
	build.7.4 \
	build.shared.7.4

push.aarch64: \
	push.7.4 \
	push.shared.7.4

#################################################################
# end of aarch64
#################################################################

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