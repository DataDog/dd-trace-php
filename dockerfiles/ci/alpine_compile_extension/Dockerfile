FROM datadog/dd-trace-ci:php-compile-extension-alpine

ADD 0001-Backport-0a39890c-Fix-libxml2-2.12-build-due-to-API-.patch /patches/0001-Backport-0a39890c-Fix-libxml2-2.12-build-due-to-API-.patch
ADD 0001-Sync-callback-signature-with-libxml2-2.9.8.patch /patches/0001-Sync-callback-signature-with-libxml2-2.9.8.patch
ADD ./docker-php-source /usr/bin/docker-php-source
ADD ./install-php /usr/bin/install-php

ARG phpVersion
ARG phpTarGzUrl
ARG phpSha256Hash
ARG phpApi

ENV SRC_DIR=/usr/src
ENV PHP_VERSION=${phpVersion}
ENV PHP_API=${phpApi}
ENV PHP_URL=${phpTarGzUrl:-https://www.php.net/distributions/php-${phpVersion}.tar.gz}
ENV PHP_SHA256=${phpSha256Hash}
ENV PHP_SRC_DIR=${SRC_DIR}/php
ENV PHP_INI_DIR /usr/local/etc/php-${phpVersion}
ENV PHP_INSTALL_DIR=/usr/local/php-${phpVersion}

RUN install-php
ENV PATH ${PATH}:${PHP_INSTALL_DIR}/bin

ADD ./env-init /root/.bashrc
ENV ENV=/root/.bashrc
ENV BASH_ENV=/root/.bashrc

CMD sh

ENV CARGO_HOME=/rust/cargo
RUN mkdir -vp "${CARGO_HOME}"
