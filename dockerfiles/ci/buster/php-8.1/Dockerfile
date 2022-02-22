FROM datadog/dd-trace-ci:buster AS base

ARG phpVersion
ENV PHP_INSTALL_DIR_DEBUG_ZTS_ASAN=${PHP_INSTALL_DIR}/debug-zts-asan
ENV PHP_INSTALL_DIR_DEBUG_NTS=${PHP_INSTALL_DIR}/debug
ENV PHP_INSTALL_DIR_NTS=${PHP_INSTALL_DIR}/nts
ENV PHP_INSTALL_DIR_ZTS=${PHP_INSTALL_DIR}/zts
ENV PHP_VERSION=${phpVersion}

FROM base as build
ARG phpTarGzUrl
ARG phpSha256Hash
RUN set -eux; \
    curl -fsSL -o /tmp/php.tar.gz "${phpTarGzUrl}"; \
    (echo "${phpSha256Hash} /tmp/php.tar.gz" | sha256sum -c -); \
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

FROM build as php-zts
RUN set -eux; \
    mkdir -p /tmp/build-php && cd /tmp/build-php; \
    /home/circleci/configure.sh \
        --enable-zts \
        --prefix=${PHP_INSTALL_DIR_ZTS} \
        --with-config-file-path=${PHP_INSTALL_DIR_ZTS} \
        --with-config-file-scan-dir=${PHP_INSTALL_DIR_ZTS}/conf.d; \
    make -j "$((`nproc`+1))"; \
    make install; \
    mkdir -p ${PHP_INSTALL_DIR_ZTS}/conf.d;

FROM base as final
COPY --chown=circleci:circleci --from=build $PHP_SRC_DIR $PHP_SRC_DIR
COPY --chown=circleci:circleci --from=php-debug-zts-asan $PHP_INSTALL_DIR_DEBUG_ZTS_ASAN $PHP_INSTALL_DIR_DEBUG_ZTS_ASAN
COPY --chown=circleci:circleci --from=php-debug $PHP_INSTALL_DIR_DEBUG_NTS $PHP_INSTALL_DIR_DEBUG_NTS
COPY --chown=circleci:circleci --from=php-nts $PHP_INSTALL_DIR_NTS $PHP_INSTALL_DIR_NTS
COPY --chown=circleci:circleci --from=php-zts $PHP_INSTALL_DIR_ZTS $PHP_INSTALL_DIR_ZTS

RUN set -eux; \
    for phpVer in $(ls ${PHP_INSTALL_DIR}); \
    do \
        echo "Install exts for PHP $phpVer..."; \
        switch-php ${phpVer}; \
        pecl channel-update pecl.php.net; \
        iniDir=$(php -i | awk -F"=> " '/Scan this dir for additional .ini files/ {print $2}'); \
        \
        yes '' | pecl install apcu; echo "extension=apcu.so" >> ${iniDir}/apcu.ini; \
        pecl install ast; echo "extension=ast.so" >> ${iniDir}/ast.ini; \
        #yes 'no' | pecl install memcached; \ # TODO Does not support PHP 8 yet
        pecl install mongodb-1.12.0; echo "extension=mongodb.so" >> ${iniDir}/mongodb.ini; \
        pecl install redis; echo "extension=redis.so" >> ${iniDir}/redis.ini; \
        # Xdebug is disabled by default \
        pecl install xdebug-3.1.2; \
        cd $(php-config --extension-dir); \
        mv xdebug.so xdebug-3.1.2.so; \
    done;

RUN set -eux; \
# Set the default PHP version
    switch-php debug;

# Install Composer
COPY --from=composer:1 /usr/bin/composer /usr/local/bin/composer

COPY welcome /etc/motd

CMD ["php-fpm", "-F"]
