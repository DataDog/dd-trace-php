PROJECT_ROOT := $(realpath ../../)
WORKDIR := /dd-trace-php

PHP_VERSIONS_ALPINE := 5.6 7.0 7.1 7.2 7.3 7.4 # (5.4 bison2 required)
PHP_VERSIONS_ALPINE_IMAGES_PUBLISH_TASKS := $(addsuffix .image.alpine.publish,$(PHP_VERSIONS_ALPINE))
PHP_VERSIONS_ALPINE_EXTENSION_BUILD_TASKS := $(addsuffix .extension.alpine.build,$(PHP_VERSIONS_ALPINE))
API_VERSION_5.4 := 20100412
API_VERSION_5.6 := 20131106
API_VERSION_7.0 := 20151012
API_VERSION_7.1 := 20160303
API_VERSION_7.2 := 20170718
API_VERSION_7.3 := 20180731
API_VERSION_7.4 := 20190902

packages: packaging-tools
	docker run --rm -v ${PROJECT_ROOT}:${WORKDIR} datadog/packaging-tools make packages

packaging-tools: Dockerfile_fpm
	docker build -t datadog/packaging-tools -f Dockerfile_fpm .

folders:
	mkdir -p ${PROJECT_ROOT}/build/packages
	rm -rf ${PROJECT_ROOT}/build/packages/*
	mkdir -p ${PROJECT_ROOT}/extensions

update-php-src:
	@if [ -d ".php-src" ]; then \
		git -C .php-src reset --hard; \
		git -C .php-src checkout master; \
		git -C .php-src pull; \
	else \
		git clone https://github.com/php/php-src.git .php-src; \
	fi

image.alpine.publish: $(PHP_VERSIONS_ALPINE_IMAGES_PUBLISH_TASKS)
	@echo "Built and published docker images to build extensions for the following php verisons: ${PHP_VERSIONS_ALPINE}"

%.image.alpine.publish: %.image.alpine.build
	docker push datadog/dd-trace-ci:php-build-extension-${*}-alpine

%.image.alpine.build: update-php-src
	docker build -t datadog/dd-trace-ci:php-build-extension-${*}-alpine --build-arg php_version=${*} -f Dockerfile_alpine --target final .

extension.alpine.build: $(PHP_VERSIONS_ALPINE_EXTENSION_BUILD_TASKS)
	@echo "Built no-debug and debug extensions for PHP version: ${PHP_VERSIONS_ALPINE}"

%.extension.alpine.build: %.extension.no-debug.alpine.build %.extension.debug.alpine.build
	@echo "Built no-debug and debug extension for PHP version: ${*}"

%.extension.no-debug.alpine.build: folders
	@echo "Building no-debug extension for PHP version: ${*}"
	docker run --rm -v ${PROJECT_ROOT}:${WORKDIR} datadog/dd-trace-ci:php-build-extension-${*}-alpine make clean dist_clean all CFLAGS="-std=gnu11 -O2 -Wall -Wextra -Werror" ECHO_ARG="-e"
	cp ${PROJECT_ROOT}/tmp/build_extension/.libs/ddtrace.so ${PROJECT_ROOT}/extensions/ddtrace-${API_VERSION_${*}}-alpine.so

%.extension.debug.alpine.build: folders
	@echo "Building debug extension for PHP version: ${*}"
	docker run --rm -v ${PROJECT_ROOT}:${WORKDIR} datadog/dd-trace-ci:php-build-extension-${*}-alpine make clean dist_clean all CFLAGS="-std=gnu11 -g -Wall -Wextra -Werror" ECHO_ARG="-e"
	cp ${PROJECT_ROOT}/tmp/build_extension/.libs/ddtrace.so ${PROJECT_ROOT}/extensions/ddtrace-${API_VERSION_${*}}-debug-alpine.so
