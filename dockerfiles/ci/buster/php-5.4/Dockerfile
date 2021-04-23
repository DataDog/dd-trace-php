FROM datadog/dd-trace-ci:buster AS base

ARG phpVersion
ENV PHP_INSTALL_DIR_DEBUG_ZTS=${PHP_INSTALL_DIR}/debug-zts
ENV PHP_INSTALL_DIR_DEBUG_NTS=${PHP_INSTALL_DIR}/debug
ENV PHP_INSTALL_DIR_NTS=${PHP_INSTALL_DIR}/nts
ENV PHP_VERSION=${phpVersion}

# Curl path workaround (PHP 5 was before pkg-config was used)
RUN set -eux; \
    sudo ln -sf /usr/include/x86_64-linux-gnu/curl /usr/include/curl; \
    sudo ln -sf /usr/lib/x86_64-linux-gnu/libcurl.a /usr/lib/libcurl.a; \
    savedAptMark="$(apt-mark showmanual)"; \
    sudo apt-mark auto '.*' > /dev/null; \
    sudo apt-mark manual $savedAptMark > /dev/null; \
    sudo apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    sudo apt-get remove -y libssl-dev; \
    { \
        echo deb http://httpredir.debian.org/debian jessie main ;\
        echo deb-src http://httpredir.debian.org/debian jessie main ;\
        echo ;\
        echo deb http://security.debian.org/ jessie/updates main ;\
        echo deb-src http://security.debian.org/ jessie/updates main ;\
    } | sudo tee /etc/apt/sources.list.d/jessie.list ;\
    { \
        echo Package: openssl libssl-dev libssl-doc;\
        echo Pin: release a=oldoldstable ;\
        echo Pin-Priority: 600 ;\
    } | sudo tee /etc/apt/preferences.d/openssl;\
    sudo apt-get update; \
    sudo apt-get install -y --no-install-recommends \
        openssl libssl-dev libssl-doc libcurl4-nss-dev;

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
        # Set default INI settings
        sed 's/;date.timezone =/date.timezone = UTC/' <${PHP_SRC_DIR}/php.ini-development >${PHP_INSTALL_DIR}/${phpVer}/php.ini; \
        \
        echo "Install exts for PHP $phpVer..."; \
        switch-php ${phpVer}; \
        pecl channel-update pecl.php.net; \
        iniDir=$(php -i | awk -F"=> " '/Scan this dir for additional .ini files/ {print $2}'); \
        \
        yes 'no' | pecl install memcached-2.2.0; echo "extension=memcached.so" >> ${iniDir}/memcached.ini; \
# Install mongo
        export MONGO_VERSION="1.6.16"; \
        export MONGO_SHA256="67886b696c428c9313539adc05f01bd87cabd57d2e861fef3c18d95375661fb2"; \
        install-ext-from-source \
            https://github.com/mongodb/mongo-php-driver-legacy/archive/refs/tags/${MONGO_VERSION}.tar.gz \
            ${MONGO_SHA256} \
            "--enable-mongo"; \
        echo "extension=mongo.so" >> ${iniDir}/mongo.ini; \
# Install xdebug
        export XDEBUG_VERSION="2.4.1"; \
        export XDEBUG_SHA256="23c8786e0f5aae67b1e5035972bfff282710fb84c483887cebceb8ef5bbdf8ef"; \
        install-ext-from-source \
            https://xdebug.org/files/xdebug-${XDEBUG_VERSION}.tgz \
            ${XDEBUG_SHA256} \
            "--enable-xdebug"; \
        # Xdebug is disabled by default
    done;

RUN set -eux; \
# Set the default PHP version
    switch-php debug;

# Install Composer
COPY --from=composer:1 /usr/bin/composer /usr/local/bin/composer

COPY welcome /etc/motd

CMD ["php-fpm", "-F"]
