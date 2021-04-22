FROM datadog/dd-trace-ci:buster AS base

ARG phpVersion
ENV PHP_INSTALL_DIR_DEBUG_ZTS=${PHP_INSTALL_DIR}/debug-zts
ENV PHP_INSTALL_DIR_DEBUG_NTS=${PHP_INSTALL_DIR}/debug
ENV PHP_INSTALL_DIR_NTS=${PHP_INSTALL_DIR}/nts
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

FROM build as php-debug-zts
RUN set -eux; \
    mkdir -p /tmp/build-php && cd /tmp/build-php; \
    /home/circleci/configure.sh \
        --enable-debug \
        --enable-maintainer-zts \
        --prefix=${PHP_INSTALL_DIR_DEBUG_ZTS} \
        --with-config-file-path=${PHP_INSTALL_DIR_DEBUG_ZTS} \
        --with-config-file-scan-dir=${PHP_INSTALL_DIR_DEBUG_ZTS}/conf.d; \
    make -j "$((`nproc`+1))"; \
    make install; \
    mkdir -p ${PHP_INSTALL_DIR_DEBUG_ZTS}/conf.d;

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
COPY --chown=circleci:circleci --from=php-debug-zts $PHP_INSTALL_DIR_DEBUG_ZTS $PHP_INSTALL_DIR_DEBUG_ZTS
COPY --chown=circleci:circleci --from=php-debug $PHP_INSTALL_DIR_DEBUG_NTS $PHP_INSTALL_DIR_DEBUG_NTS
COPY --chown=circleci:circleci --from=php-nts $PHP_INSTALL_DIR_NTS $PHP_INSTALL_DIR_NTS

RUN set -eux; \
    for phpVer in $(ls ${PHP_INSTALL_DIR}); \
    do \
        echo "Install exts for PHP $phpVer..."; \
        switch-php ${phpVer}; \
        iniDir=$(php -i | awk -F"=> " '/Scan this dir for additional .ini files/ {print $2}'); \
        \
        pecl channel-update pecl.php.net; \
        yes '' | pecl install apcu; echo "extension=apcu.so" >> ${iniDir}/apcu.ini; \
        pecl install ast; echo "extension=ast.so" >> ${iniDir}/ast.ini; \
        yes 'no' | pecl install memcached; echo "extension=memcached.so" >> ${iniDir}/memcached.ini; \
        pecl install mongodb; echo "extension=mongodb.so" >> ${iniDir}/mongodb.ini; \
        pecl install redis; echo "extension=redis.so" >> ${iniDir}/redis.ini; \
        # Xdebug is disabled by default
        pecl install xdebug-2.7.2; \
        cd $(php-config --extension-dir); \
        mv xdebug.so xdebug-2.7.2.so; \
    done;

RUN set -eux; \
# Set the default PHP version
    switch-php debug;

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY welcome /etc/motd

CMD ["php-fpm", "-F"]
