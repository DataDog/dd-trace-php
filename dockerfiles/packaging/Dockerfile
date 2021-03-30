ARG DOCKER_REGISTRY=docker.io

FROM scratch as src
COPY / / 

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

# copy all built extensions into a single tmp image
FROM scratch as extensions
COPY --from=php-7.3 /build/extensions/*.so /
COPY --from=php-7.3-zts /build/extensions/*.so /
COPY --from=php-7.3-debug /build/extensions/*.so /

FROM ${DOCKER_REGISTRY}/datadog/docker-library:ddtrace_php_fpm_packaging as packager
WORKDIR /build/
COPY --from=src / /build/
COPY --from=extensions / /build/extensions/
RUN make packages

# Unused step for exporting pure .so files for debugging use following cmd lines to export a layer
# with only extension files
# docker build -f dockerfiles/packaging/Dockerfile . --target extension_export -t tmp_extension_export
# docker image save tmp_extension_export  | tar --strip-components=1 -x '*/layer.tar'
FROM scratch as extension_export
# squash layers
COPY --from=extensions / / 

FROM scratch AS export
COPY --from=packager /build/build/packages/* /

# Build instructions
# docker build -f dockerfiles/packaging/Dockerfile . --target export -t tmp_export && docker image save tmp_export  | tar --strip-components=1 -x '*/layer.tar'
