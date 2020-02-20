PROJECT_ROOT := $(realpath ../../)
WORKDIR := /dd-trace-php

ALL_ALPINE := 5.6.alpine 7.0.alpine 7.1.alpine 7.2.alpine 7.3.alpine 7.4.alpine

package: packaging-tools 5.6-alpine
	docker run --rm -v ${PROJECT_ROOT}:${WORKDIR} datadog/packaging-tools make .apk

packaging-tools: Dockerfile_fpm
	docker build -t datadog/packaging-tools -f Dockerfile_fpm .

folders:
	mkdir -p ${PROJECT_ROOT}/build/packages
	rm -rf ${PROJECT_ROOT}/build/packages/*
	mkdir -p ${PROJECT_ROOT}/extensions

php-src:
	git clone https://github.com/php/php-src.git .php-src

all.alpine: $(ALL_ALPINE)

5.4.alpine: folders
	# Temporarily not using alpine to build 5.4 as it requires bison 2 which requires some effort to be installed
	# in alpine
	# $(call docker-build-alpine,$@,5.4)
	# $(call docker-build-extension,$@)
	# $(call copy-extension-alpine,20100412)

5.6.alpine: folders
	$(call docker-build-alpine,$@,5.6)
	$(call docker-build-extension,$@)
	$(call copy-extension-alpine,20131106)
	$(call docker-build-extension-debug,$@)
	$(call copy-extension-alpine,20131106-debug)

7.0.alpine: folders
	$(call docker-build-alpine,$@,7.0)
	$(call docker-build-extension,$@)
	$(call copy-extension-alpine,20151012)
	$(call docker-build-extension-debug,$@)
	$(call copy-extension-alpine,20151012-debug)

7.1.alpine: folders
	$(call docker-build-alpine,$@,7.1)
	$(call docker-build-extension,$@)
	$(call copy-extension-alpine,20160303)
	$(call docker-build-extension-debug,$@)
	$(call copy-extension-alpine,20160303-debug)

7.2.alpine: folders
	$(call docker-build-alpine,$@,7.2)
	$(call docker-build-extension,$@)
	$(call copy-extension-alpine,20170718)
	$(call docker-build-extension-debug,$@)
	$(call copy-extension-alpine,20170718-debug)

7.3.alpine: folders
	# Temporarily not using alpine to build 7.3 as it generates an error to be investigated
	# $(call docker-build-alpine,$@,7.3)
	# $(call docker-build-extension,$@)
	# $(call copy-extension-alpine,20180731)
	# $(call docker-build-extension-debug,$@)
	# $(call copy-extension-alpine,20180731-debug)

7.4.alpine: folders
	$(call docker-build-alpine,$@,7.4)
	$(call docker-build-extension,$@)
	$(call copy-extension-alpine,20190902)
	$(call docker-build-extension-debug,$@)
	$(call copy-extension-alpine,20190902-debug)

.PHONY: folders package packaging-tools php-src all.alpine 5.6.alpine 7.0.alpine 7.1.alpine 7.2.alpine 7.3.alpine 7.4.alpine

define docker-build-alpine
	docker build -t ${1} --build-arg php_version=${2} -f Dockerfile_alpine --target final .
endef

define docker-build-extension
	docker run --rm -v ${PROJECT_ROOT}:${WORKDIR} ${1} make clean dist_clean all CFLAGS="-std=gnu11 -O2 -Wall -Wextra -Werror" ECHO_ARG="-e"
endef

define docker-build-extension-debug
	docker run --rm -v ${PROJECT_ROOT}:${WORKDIR} ${1} make clean dist_clean all CFLAGS="-std=gnu11 -g -Wall -Wextra -Werror" ECHO_ARG="-e"
endef

define copy-extension-alpine
	cp ${PROJECT_ROOT}/tmp/build_extension/.libs/ddtrace.so ${PROJECT_ROOT}/extensions/ddtrace-${1}-alpine.so
endef
