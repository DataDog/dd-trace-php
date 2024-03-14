FROM alpine:3.18 as base

RUN mkdir -p /app
WORKDIR /app

# Break the dependencies into multiple layers to reduce individual layer size.
# This is just to avoid an upload issue I was hitting with large layers.
RUN set -eux; \
    apk add --no-cache \
        bash \
        build-base \
        curl-dev \
        libedit-dev \
        libffi-dev \
        libmcrypt-dev \
        libsodium-dev \
        libxml2-dev \
        gnu-libiconv-dev \
        oniguruma-dev

# Profiling deps
# Minimum: libclang. Nice-to-have: full toolchain including linker to play
# with cross-language link-time optimization. Needs to match rustc -Vv's llvm
# version.
RUN apk add --no-cache llvm16-libs clang16-dev lld llvm16
RUN apk add --no-cache rust-stdlib
RUN apk add --no-cache cargo
RUN apk add --no-cache clang git protoc unzip

FROM base

ADD ./docker-php-source /usr/bin/docker-php-source
ADD ./install-php /usr/bin/install-php

ARG php_version
ARG php_url
ARG php_sha
ARG php_api

ENV SRC_DIR=/usr/src
ENV PHP_VERSION=${php_version}
ENV PHP_API=${php_api}
ENV PHP_URL=${php_url:-https://www.php.net/distributions/php-${php_version}.tar.gz}
ENV PHP_SHA256=${php_sha}
ENV PHP_SRC_DIR=${SRC_DIR}/php
ENV PHP_INI_DIR /usr/local/etc/php-${php_version}
ENV PHP_INSTALL_DIR=/usr/local/php-${php_version}

RUN install-php
ENV PATH ${PATH}:${PHP_INSTALL_DIR}/bin

ADD ./build-dd-trace-php /usr/bin/build-dd-trace-php

ADD ./env-init /root/.bashrc
ENV ENV=/root/.bashrc
ENV BASH_ENV=/root/.bashrc

CMD sh

ENV CARGO_HOME=/rust/cargo
RUN mkdir -vp "${CARGO_HOME}"
