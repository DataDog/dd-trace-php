FROM datadog/dd-trace-ci:buster AS base

ARG phpVersion
ENV PHP_INSTALL_DIR_DEBUG_ZTS_ASAN=${PHP_INSTALL_DIR}/${phpVersion}-debug-zts-asan
ENV PHP_INSTALL_DIR_DEBUG_NTS=${PHP_INSTALL_DIR}/${phpVersion}-debug
ENV PHP_INSTALL_DIR_NTS=${PHP_INSTALL_DIR}/${phpVersion}
ENV PHP_VERSION=${phpVersion}

FROM base as build
ARG phpTarGzUrl
RUN set -eux; \
    curl -fsSL -o /tmp/php.tar.gz "${phpTarGzUrl}"; \
    tar xf /tmp/php.tar.gz -C "${PHP_SRC_DIR}" --strip-components=1; \
    rm -f /tmp/php.tar.gz; \
    cd ${PHP_SRC_DIR}; \
    ./buildconf --force;
COPY configure.sh /home/circleci

FROM build as php-debug-zts-asan
ENV CFLAGS='-fsanitize=address -DZEND_TRACK_ARENA_ALLOC'
ENV LDFLAGS='-fsanitize=address'
RUN set -eux; \
    mkdir -p /tmp/build-php && cd /tmp/build-php; \
    /home/circleci/configure.sh \
        --enable-debug \
        --enable-zts \
        --prefix=${PHP_INSTALL_DIR_DEBUG_ZTS_ASAN} \
        --with-config-file-path=${PHP_INSTALL_DIR_DEBUG_ZTS_ASAN} \
        --with-config-file-scan-dir=${PHP_INSTALL_DIR_DEBUG_ZTS_ASAN}/conf.d; \
    make -j "$((`nproc`+1))"; \
    make install; \
    mkdir -p ${PHP_INSTALL_DIR_DEBUG_ZTS_ASAN}/conf.d;

FROM build as php-debug
RUN set -eux; \
    mkdir -p /tmp/build-php && cd /tmp/build-php; \
    /home/circleci/configure.sh \
        --enable-debug \
        --prefix=${PHP_INSTALL_DIR_DEBUG_NTS} \
        --with-config-file-path=${PHP_INSTALL_DIR_DEBUG_NTS} \
        --with-config-file-scan-dir=${PHP_INSTALL_DIR_DEBUG_NTS}/conf.d; \
    make -j "$((`nproc`+1))"; \
    make install; \
    mkdir -p ${PHP_INSTALL_DIR_DEBUG_NTS}/conf.d;

FROM build as php-nts
RUN set -eux; \
    mkdir -p /tmp/build-php && cd /tmp/build-php; \
    /home/circleci/configure.sh \
        --prefix=${PHP_INSTALL_DIR_NTS} \
        --with-config-file-path=${PHP_INSTALL_DIR_NTS} \
        --with-config-file-scan-dir=${PHP_INSTALL_DIR_NTS}/conf.d; \
    make -j "$((`nproc`+1))"; \
    make install; \
    mkdir -p ${PHP_INSTALL_DIR_NTS}/conf.d;

FROM base as final
COPY --chown=circleci:circleci --from=build $PHP_SRC_DIR $PHP_SRC_DIR
COPY --chown=circleci:circleci --from=php-debug-zts-asan $PHP_INSTALL_DIR_DEBUG_ZTS_ASAN $PHP_INSTALL_DIR_DEBUG_ZTS_ASAN
COPY --chown=circleci:circleci --from=php-debug $PHP_INSTALL_DIR_DEBUG_NTS $PHP_INSTALL_DIR_DEBUG_NTS
COPY --chown=circleci:circleci --from=php-nts $PHP_INSTALL_DIR_NTS $PHP_INSTALL_DIR_NTS

RUN set -eux; \
# Set the default PHP version
    switch-php ${PHP_VERSION}-debug;

# TODO Install memcached
# TODO Install mcrypt
# TODO Install Xdebug (two versions for testing on PHP 7.1 - 7.4)
# TODO Install ast
# TODO Install sodium
# TODO Install redis
# TODO Install mongodb

# Install Composer
RUN set -eux; \
    curl -q https://raw.githubusercontent.com/composer/getcomposer.org/76a7060ccb93902cd7576b67264ad91c8a2700e2/web/installer | \
    sudo php -- --filename=composer --install-dir=/usr/local/bin;

COPY welcome /etc/motd

CMD ["php-fpm", "-F"]
