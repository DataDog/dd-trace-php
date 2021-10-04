FROM datadog/dd-trace-ci:centos-6 as base

ARG phpVersion
ENV PHP_INSTALL_DIR_ZTS=${PHP_INSTALL_DIR}/${phpVersion}-zts
ENV PHP_INSTALL_DIR_DEBUG_NTS=${PHP_INSTALL_DIR}/${phpVersion}-debug
ENV PHP_INSTALL_DIR_NTS=${PHP_INSTALL_DIR}/${phpVersion}
ENV PHP_VERSION=${phpVersion}

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
    ${PHP_SRC_DIR}/buildconf --force;

FROM base as build
COPY php-${PHP_VERSION}/configure.sh /root/

FROM build as php-zts
RUN bash -c 'set -eux; \
    mkdir -p /tmp/build-php && cd /tmp/build-php; \
    /root/configure.sh \
        --enable-zts \
        --prefix=${PHP_INSTALL_DIR_ZTS} \
        --with-config-file-path=${PHP_INSTALL_DIR_ZTS} \
        --with-config-file-scan-dir=${PHP_INSTALL_DIR_ZTS}/conf.d; \
    make -j "$((`nproc`+1))"; \
    make install; \
    mkdir -p ${PHP_INSTALL_DIR_ZTS}/conf.d;'

FROM build as php-debug
RUN bash -c 'set -eux; \
    mkdir -p /tmp/build-php && cd /tmp/build-php; \
    /root/configure.sh \
        --enable-debug \
        --prefix=${PHP_INSTALL_DIR_DEBUG_NTS} \
        --with-config-file-path=${PHP_INSTALL_DIR_DEBUG_NTS} \
        --with-config-file-scan-dir=${PHP_INSTALL_DIR_DEBUG_NTS}/conf.d; \
    make -j "$((`nproc`+1))"; \
    make install; \
    mkdir -p ${PHP_INSTALL_DIR_DEBUG_NTS}/conf.d;'

FROM build as php-nts
RUN bash -c 'set -eux; \
    mkdir -p /tmp/build-php && cd /tmp/build-php; \
    /root/configure.sh \
        --prefix=${PHP_INSTALL_DIR_NTS} \
        --with-config-file-path=${PHP_INSTALL_DIR_NTS} \
        --with-config-file-scan-dir=${PHP_INSTALL_DIR_NTS}/conf.d; \
    make -j "$((`nproc`+1))"; \
    make install; \
    mkdir -p ${PHP_INSTALL_DIR_NTS}/conf.d;'

FROM base as final
COPY --from=php-zts $PHP_INSTALL_DIR_ZTS $PHP_INSTALL_DIR_ZTS
COPY --from=php-debug $PHP_INSTALL_DIR_DEBUG_NTS $PHP_INSTALL_DIR_DEBUG_NTS
COPY --from=php-nts $PHP_INSTALL_DIR_NTS $PHP_INSTALL_DIR_NTS

RUN set -eux; \
# Set the default PHP version
    switch-php ${PHP_VERSION};
