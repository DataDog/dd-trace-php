PROJECT_ROOT := $(realpath ../../)
WORKDIR := /dd-trace-php

package: packaging-tools 5.6-alpine
	docker run --rm -v ${PROJECT_ROOT}:${WORKDIR} datadog/packaging-tools make .apk

packaging-tools: Dockerfile_fpm
	docker build -t datadog/packaging-tools -f Dockerfile_fpm .

5.6-alpine:
	mkdir -p ${PROJECT_ROOT}/build/packages
	rm -rf ${PROJECT_ROOT}/build/packages/*
	mkdir -p ${PROJECT_ROOT}/extensions
	docker build -t $@ -f Dockerfile_alpine_3.5 .
	docker run --rm -v ${PROJECT_ROOT}:${WORKDIR} $@ make clean dist_clean all CFLAGS="-std=gnu11 -O2 -Wall -Wextra -Werror" ECHO_ARG="-e"
	cp ${PROJECT_ROOT}/tmp/build_extension/.libs/ddtrace.so ${PROJECT_ROOT}/extensions/ddtrace-20131106.so
	# docker run --rm -v ${PROJECT_ROOT}:${WORKDIR} -w ${WORKDIR} datadog/packaging-tools make packages

.PHONY: 5.6-alpine package packaging-tools
