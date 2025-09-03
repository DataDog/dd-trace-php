FROM datadog/dd-trace-ci:centos-7 AS base

ENV PHP_SRC_DIR=/usr/local/src/php
ENV PHP_INSTALL_DIR=/opt/php

ARG phpVersion
ENV PHP_INSTALL_DIR_ZTS=${PHP_INSTALL_DIR}/${phpVersion}-zts
ENV PHP_INSTALL_DIR_DEBUG_NTS=${PHP_INSTALL_DIR}/${phpVersion}-debug
ENV PHP_INSTALL_DIR_NTS=${PHP_INSTALL_DIR}/${phpVersion}
ENV PHP_VERSION=${phpVersion}

# Need a new `cert.pem` as otherwise pecl will not work
RUN cd /usr/local/openssl/; \
    curl -Lo cert.pem http://curl.haxx.se/ca/cacert.pem;

# Download and extract PHP source
ARG phpTarGzUrl
ARG phpSha256Hash
RUN set -eux; \
    mkdir -p $PHP_SRC_DIR; \
    mkdir -p $PHP_INSTALL_DIR; \
    curl -fsSL -o /tmp/php.tar.gz "${phpTarGzUrl}"; \
    (echo "${phpSha256Hash}  /tmp/php.tar.gz" | sha256sum -c -); \
    tar xf /tmp/php.tar.gz -C "${PHP_SRC_DIR}" --strip-components=1; \
    rm -f /tmp/php.tar.gz; \
    [ $(expr substr ${PHP_VERSION} 1 1) = 7 ] || ${PHP_SRC_DIR}/buildconf --force

COPY php-${PHP_VERSION}/configure.sh /root/

FROM base AS php-zts
RUN bash -c 'set -eux; \
    if [ "$(uname -m)" = "aarch64" ]; then \
      export CFLAGS="${CFLAGS:-} -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=0"; \
      export CPPFLAGS="${CPPFLAGS:-} -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=0"; \
    fi; \
    mkdir -p /tmp/build-php && cd /tmp/build-php \
    && /root/configure.sh \
        --enable-$(if [ $(expr substr ${PHP_VERSION} 1 1) = 7 ]; then echo maintainer-; fi)zts \
        --prefix=${PHP_INSTALL_DIR_ZTS} \
        --with-config-file-path=${PHP_INSTALL_DIR_ZTS} \
        --with-config-file-scan-dir=${PHP_INSTALL_DIR_ZTS}/conf.d \
    && make -j 2 \
    && make install \
    && cp .libs/libphp*.so ${PHP_INSTALL_DIR_ZTS}/lib/apache2handler-libphp.so \
    && mkdir -p ${PHP_INSTALL_DIR_ZTS}/conf.d' \
    && [ $(expr substr ${PHP_VERSION} 1 1) = 7 ] || ${PHP_INSTALL_DIR_ZTS}/bin/pecl install parallel || true \
    && [ $(expr substr ${PHP_VERSION} 1 1) = 7 ] || echo "extension=parallel" >> ${PHP_INSTALL_DIR_ZTS}/conf.d/parallel.ini || true

FROM base AS php-debug
RUN bash -c 'set -eux; \
    if [ "$(uname -m)" = "aarch64" ]; then \
      export CFLAGS="${CFLAGS:-} -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=0"; \
      export CPPFLAGS="${CPPFLAGS:-} -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=0"; \
    fi; \
    mkdir -p /tmp/build-php && cd /tmp/build-php \
    && /root/configure.sh \
        --enable-debug \
        --prefix=${PHP_INSTALL_DIR_DEBUG_NTS} \
        --with-config-file-path=${PHP_INSTALL_DIR_DEBUG_NTS} \
        --with-config-file-scan-dir=${PHP_INSTALL_DIR_DEBUG_NTS}/conf.d \
    && make -j 2 \
    && make install \
    && cp .libs/libphp*.so ${PHP_INSTALL_DIR_DEBUG_NTS}/lib/apache2handler-libphp.so \
    && mkdir -p ${PHP_INSTALL_DIR_DEBUG_NTS}/conf.d'

FROM base AS php-nts
RUN bash -c 'set -eux; \
    if [ "$(uname -m)" = "aarch64" ]; then \
      export CFLAGS="${CFLAGS:-} -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=0"; \
      export CPPFLAGS="${CPPFLAGS:-} -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=0"; \
    fi; \
    mkdir -p /tmp/build-php && cd /tmp/build-php \
    && /root/configure.sh \
        --prefix=${PHP_INSTALL_DIR_NTS} \
        --with-config-file-path=${PHP_INSTALL_DIR_NTS} \
        --with-config-file-scan-dir=${PHP_INSTALL_DIR_NTS}/conf.d \
    && make -j 2 \
    && make install \
    && cp .libs/libphp*.so ${PHP_INSTALL_DIR_NTS}/lib/apache2handler-libphp.so \
    && mkdir -p ${PHP_INSTALL_DIR_NTS}/conf.d'

FROM base AS final
COPY --from=php-zts $PHP_INSTALL_DIR_ZTS $PHP_INSTALL_DIR_ZTS
COPY --from=php-debug $PHP_INSTALL_DIR_DEBUG_NTS $PHP_INSTALL_DIR_DEBUG_NTS
COPY --from=php-nts $PHP_INSTALL_DIR_NTS $PHP_INSTALL_DIR_NTS

COPY switch-php /usr/local/bin/

RUN set -eux; \
    # Enable the apache config \
    echo "LoadModule php$(if [ $(expr substr ${PHP_VERSION} 1 1) = 7 ]; then echo 7; fi)_module modules/mod_libphp.so" | tee /etc/httpd/conf.modules.d/99-php.conf; \
# Set the default PHP version
    switch-php ${PHP_VERSION};
