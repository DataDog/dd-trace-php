# 

### syntax=docker/dockerfile-upstream:master
ARG DOCKER_REGISTRY=docker.io

FROM scratch as full_src
COPY / /

FROM scratch as src
COPY dockerfiles/packaging/build.sh dockerfiles/packaging/build.sh
COPY dockerfiles/packaging/build-alpine.sh dockerfiles/packaging/build-alpine.sh
COPY dockerfiles/packaging/prepare-alpine.sh dockerfiles/packaging/prepare-alpine.sh
COPY Makefile .
COPY ext ext
COPY src/DDTrace/Tracer.php src/DDTrace/Tracer.php
COPY src/dogstatsd src/dogstatsd
COPY components components
COPY zend_abstract_interface zend_abstract_interface
COPY m4 m4
COPY tests/ext tests/ext
COPY tests/C2PHP tests/C2PHP
COPY ddtrace.sym ddtrace.sym
COPY config.m4 config.m4

### PHP 5.4

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-5.4_centos-6 as php-5.4-base
COPY --from=src / /build/
WORKDIR /build/

FROM php-5.4-base as php-5.4
RUN bash dockerfiles/packaging/build.sh 5.4 20100412

FROM php-5.4-base as php-5.4-zts
RUN bash dockerfiles/packaging/build.sh 5.4-zts 20100412-zts

FROM php-5.4-base as php-5.4-debug
ENV CFLAGS="-std=gnu11 -O0 -g -Wall -Wextra"
RUN bash dockerfiles/packaging/build.sh 5.4 20100412-debug

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-compile-extension-alpine-5.4 as php-5.4-base-alpine
COPY --from=src / /build/
WORKDIR /build/

FROM php-5.4-base-alpine as php-5.4-alpine
RUN sh dockerfiles/packaging/prepare-alpine.sh
RUN sh dockerfiles/packaging/build-alpine.sh 5.4 20100412

### PHP 5.5

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-5.5_centos-6 as php-5.5-base
COPY --from=src / /build/
WORKDIR /build/

FROM php-5.5-base as php-5.5
RUN bash dockerfiles/packaging/build.sh 5.5 20121113

FROM php-5.5-base as php-5.5-zts
RUN bash dockerfiles/packaging/build.sh 5.5-zts 20121113-zts

FROM php-5.5-base as php-5.5-debug
ENV CFLAGS="-std=gnu11 -O0 -g -Wall -Wextra"
RUN bash dockerfiles/packaging/build.sh 5.5 20121113-debug

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-compile-extension-alpine-5.5 as php-5.5-base-alpine
COPY --from=src / /build/
WORKDIR /build/

FROM php-5.5-base-alpine as php-5.5-alpine
RUN sh dockerfiles/packaging/prepare-alpine.sh
RUN sh dockerfiles/packaging/build-alpine.sh 5.5 20121113

### PHP 5.6

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-5.6_centos-6 as php-5.6-base
COPY --from=src / /build/
WORKDIR /build/

FROM php-5.6-base as php-5.6
RUN bash dockerfiles/packaging/build.sh 5.6 20131106

FROM php-5.6-base as php-5.6-zts
RUN bash dockerfiles/packaging/build.sh 5.6-zts 20131106-zts

FROM php-5.6-base as php-5.6-debug
ENV CFLAGS="-std=gnu11 -O0 -g -Wall -Wextra"
RUN bash dockerfiles/packaging/build.sh 5.6 20131106-debug

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-compile-extension-alpine-5.6 as php-5.6-base-alpine
COPY --from=src / /build/
WORKDIR /build/

FROM php-5.6-base-alpine as php-5.6-alpine
RUN sh dockerfiles/packaging/prepare-alpine.sh
RUN sh dockerfiles/packaging/build-alpine.sh 5.6 20131106

FROM scratch as php-5.x-output
COPY --from=php-5.4 /build/extensions/*.so /
COPY --from=php-5.4-zts /build/extensions/*.so /
COPY --from=php-5.4-debug /build/extensions/*.so /
COPY --from=php-5.4-alpine /build/extensions/*.so /
COPY --from=php-5.5 /build/extensions/*.so /
COPY --from=php-5.5-zts /build/extensions/*.so /
COPY --from=php-5.5-debug /build/extensions/*.so /
COPY --from=php-5.5-alpine /build/extensions/*.so /
COPY --from=php-5.6 /build/extensions/*.so /
COPY --from=php-5.6-zts /build/extensions/*.so /
COPY --from=php-5.6-debug /build/extensions/*.so /
COPY --from=php-5.6-alpine /build/extensions/*.so /

### PHP 7.0

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-7.0_centos-6 as php-7.0-base
COPY --from=src / /build/
WORKDIR /build/

FROM php-7.0-base as php-7.0
RUN bash dockerfiles/packaging/build.sh 7.0 20151012

FROM php-7.0-base as php-7.0-zts
RUN bash dockerfiles/packaging/build.sh 7.0-zts 20151012-zts

FROM php-7.0-base as php-7.0-debug
ENV CFLAGS="-std=gnu11 -O0 -g -Wall -Wextra"
RUN bash dockerfiles/packaging/build.sh 7.0 20151012-debug

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-compile-extension-alpine-7.0 as php-7.0-base-alpine
COPY --from=src / /build/
WORKDIR /build/

FROM php-7.0-base-alpine as php-7.0-alpine
RUN sh dockerfiles/packaging/prepare-alpine.sh
RUN sh dockerfiles/packaging/build-alpine.sh 7.0 20151012

FROM scratch AS php-7.0-output
COPY --from=php-7.0 /build/extensions/*.so /
COPY --from=php-7.0-zts /build/extensions/*.so /
COPY --from=php-7.0-debug /build/extensions/*.so /
COPY --from=php-7.0-alpine /build/extensions/*.so /

### PHP 7.1

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-7.1_centos-6 as php-7.1-base
COPY --from=src / /build/
WORKDIR /build/

FROM php-7.1-base as php-7.1
RUN bash dockerfiles/packaging/build.sh 7.1 20160303

FROM php-7.1-base as php-7.1-zts
RUN bash dockerfiles/packaging/build.sh 7.1-zts 20160303-zts

FROM php-7.1-base as php-7.1-debug
ENV CFLAGS="-std=gnu11 -O0 -g -Wall -Wextra"
RUN bash dockerfiles/packaging/build.sh 7.1 20160303-debug

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-compile-extension-alpine-7.1 as php-7.1-base-alpine
COPY --from=src / /build/
WORKDIR /build/

FROM php-7.1-base-alpine as php-7.1-alpine
RUN sh dockerfiles/packaging/prepare-alpine.sh
RUN sh dockerfiles/packaging/build-alpine.sh 7.1 20160303

FROM scratch as php-7.1-output
COPY --from=php-7.1 /build/extensions/*.so /
COPY --from=php-7.1-zts /build/extensions/*.so /
COPY --from=php-7.1-debug /build/extensions/*.so /
COPY --from=php-7.1-alpine /build/extensions/*.so /

### PHP 7.2

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-7.2_centos-6 as php-7.2-base
COPY --from=src / /build/
WORKDIR /build/

FROM php-7.2-base as php-7.2
RUN bash dockerfiles/packaging/build.sh 7.2 20170718

FROM php-7.2-base as php-7.2-zts
RUN bash dockerfiles/packaging/build.sh 7.2-zts 20170718-zts

FROM php-7.2-base as php-7.2-debug
ENV CFLAGS="-std=gnu11 -O0 -g -Wall -Wextra"
RUN bash dockerfiles/packaging/build.sh 7.2 20170718-debug

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-compile-extension-alpine-7.2 as php-7.2-base-alpine
COPY --from=src / /build/
WORKDIR /build/

FROM php-7.2-base-alpine as php-7.2-alpine
RUN sh dockerfiles/packaging/prepare-alpine.sh
RUN sh dockerfiles/packaging/build-alpine.sh 7.2 20170718

FROM scratch as php-7.2-output
COPY --from=php-7.2 /build/extensions/*.so /
COPY --from=php-7.2-zts /build/extensions/*.so /
COPY --from=php-7.2-debug /build/extensions/*.so /
COPY --from=php-7.2-alpine /build/extensions/*.so /

### PHP 7.3

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-7.3_centos-6 as php-7.3-base
COPY --from=src / /build/
WORKDIR /build/

FROM php-7.3-base as php-7.3
RUN bash dockerfiles/packaging/build.sh 7.3 20180731

FROM php-7.3-base as php-7.3-zts
RUN bash dockerfiles/packaging/build.sh 7.3-zts 20180731-zts

FROM php-7.3-base as php-7.3-debug
ENV CFLAGS="-std=gnu11 -O0 -g -Wall -Wextra"
RUN bash dockerfiles/packaging/build.sh 7.3 20180731-debug

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-compile-extension-alpine-7.3 as php-7.3-base-alpine
COPY --from=src / /build/
WORKDIR /build/

FROM php-7.3-base-alpine as php-7.3-alpine
RUN sh dockerfiles/packaging/prepare-alpine.sh
RUN sh dockerfiles/packaging/build-alpine.sh 7.3 20180731

FROM scratch as php-7.3-output
COPY --from=php-7.3 /build/extensions/*.so /
COPY --from=php-7.3-zts /build/extensions/*.so /
COPY --from=php-7.3-debug /build/extensions/*.so /
COPY --from=php-7.3-alpine /build/extensions/*.so /

### PHP 7.4

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-7.4_centos-6 as php-7.4-base
COPY --from=src / /build/
WORKDIR /build/

FROM php-7.4-base as php-7.4
RUN bash dockerfiles/packaging/build.sh 7.4 20190902

FROM php-7.4-base as php-7.4-zts
RUN bash dockerfiles/packaging/build.sh 7.4-zts 20190902-zts

FROM php-7.4-base as php-7.4-debug
ENV CFLAGS="-std=gnu11 -O0 -g -Wall -Wextra"
RUN bash dockerfiles/packaging/build.sh 7.4 20190902-debug

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-compile-extension-alpine-7.4 as php-7.4-base-alpine
COPY --from=src / /build/
WORKDIR /build/

FROM php-7.4-base-alpine as php-7.4-alpine
RUN sh dockerfiles/packaging/prepare-alpine.sh
RUN sh dockerfiles/packaging/build-alpine.sh 7.4 20190902

FROM scratch as php-7.4-output
COPY --from=php-7.4 /build/extensions/*.so /
COPY --from=php-7.4-zts /build/extensions/*.so /
COPY --from=php-7.4-debug /build/extensions/*.so /
COPY --from=php-7.4-alpine /build/extensions/*.so /

### PHP 8.0

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-8.0_centos-6 as php-8.0-base
COPY --from=src / /build/
WORKDIR /build/

FROM php-8.0-base as php-8.0
RUN bash dockerfiles/packaging/build.sh 8.0 20200930

FROM php-8.0-base as php-8.0-zts
RUN bash dockerfiles/packaging/build.sh 8.0-zts 20200930-zts

FROM php-8.0-base as php-8.0-debug
ENV CFLAGS="-std=gnu11 -O0 -g -Wall -Wextra"
RUN bash dockerfiles/packaging/build.sh 8.0 20200930-debug

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-compile-extension-alpine-8.0 as php-8.0-base-alpine
COPY --from=src / /build/
WORKDIR /build/

FROM php-8.0-base-alpine as php-8.0-alpine
RUN sh dockerfiles/packaging/prepare-alpine.sh
RUN sh dockerfiles/packaging/build-alpine.sh 8.0 20200930

FROM scratch as php-8.0-output
COPY --from=php-8.0 /build/extensions/*.so /
COPY --from=php-8.0-zts /build/extensions/*.so /
COPY --from=php-8.0-debug /build/extensions/*.so /
COPY --from=php-8.0-alpine /build/extensions/*.so /

# 8.1 
FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-8.1_centos-6 as php-8.1-base
COPY --from=src / /build/
WORKDIR /build/

FROM php-8.1-base as php-8.1
RUN bash dockerfiles/packaging/build.sh 8.1 20210902

FROM php-8.1-base as php-8.1-zts
RUN bash dockerfiles/packaging/build.sh 8.1-zts 20210902-zts

FROM php-8.1-base as php-8.1-debug
ENV CFLAGS="-std=gnu11 -O0 -g -Wall -Wextra"
RUN bash dockerfiles/packaging/build.sh 8.0 20210902-debug

FROM ${DOCKER_REGISTRY}/datadog/dd-trace-ci:php-compile-extension-alpine-8.1 as php-8.1-base-alpine
COPY --from=src / /build/
WORKDIR /build/

FROM php-8.1-base-alpine as php-8.1-alpine
RUN sh dockerfiles/packaging/prepare-alpine.sh
RUN sh dockerfiles/packaging/build-alpine.sh 8.0 20210902

FROM scratch as php-8.1-output
COPY --from=php-8.1 /build/extensions/*.so /
COPY --from=php-8.1-zts /build/extensions/*.so /
COPY --from=php-8.1-debug /build/extensions/*.so /
COPY --from=php-8.1-alpine /build/extensions/*.so /

# copy all built extensions into a single tmp image
FROM scratch as extensions
COPY --from=php-5.x-output /*.so /
COPY --from=php-7.0-output /*.so /
COPY --from=php-7.1-output /*.so /
COPY --from=php-7.2-output /*.so /
COPY --from=php-7.3-output /*.so /
COPY --from=php-7.4-output /*.so /
COPY --from=php-8.0-output /*.so /
COPY --from=php-8.1-output /*.so /


FROM ${DOCKER_REGISTRY}/datadog/docker-library:ddtrace_php_fpm_packaging as packager
WORKDIR /build/
COPY --from=full_src / /build/
COPY --from=extensions / /build/extensions/
RUN make packages

# Unused step for exporting pure .so files for debugging use following cmd lines to export a layer
# with only extension files
# docker build -f dockerfiles/packaging/Dockerfile . --target extension_export -t tmp_extension_export
# docker image save tmp_extension_export | tar --strip-components=1 -x '*/layer.tar'
FROM scratch as extension_export
# squash layers
COPY --from=extensions / /

FROM scratch AS export
COPY --from=packager /build/build/packages/* /

# Build instructions
# docker build -f dockerfiles/packaging/Dockerfile . --target export -t tmp_export && docker image save tmp_export  | tar --strip-components=1 -x '*/layer.tar'
