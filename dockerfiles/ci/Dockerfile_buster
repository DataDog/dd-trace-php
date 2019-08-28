ARG VERSION=v7_3
ARG VARIANT=vanilla
FROM debian:buster-slim AS basic_system
ENV DEBIAN_FRONTEND=noninteractive

ENV RUNTIME_DEPS \
    sudo \
    netbase \
    git \
    vim \
    valgrind \
    gdb \
    less

ENV DDTRACE_EXTENSION_DEPS \
    libcurl4-openssl-dev

ENV PHPIZE_DEPS \
        autoconf \
        dpkg-dev \
        file \
        g++ \
        gcc \
        libc-dev \
        make \
        pkg-config \
        re2c \
        $DDTRACE_EXTENSION_DEPS

ENV PHP_INI_DIR /usr/local/etc/php
ENV LANG=C.UTF-8
ENV APACHE_CONFDIR /etc/apache2
ENV APACHE_ENVVARS $APACHE_CONFDIR/envvars

RUN set -eux; \
    echo 'APT::Get::Assume-Yes "true";' > /etc/apt/apt.conf.d/90circleci; \
    echo 'DPkg::Options "--force-confnew";' >> /etc/apt/apt.conf.d/90circleci; \
    \
# prevent Debian's PHP packages from being installed
# https://github.com/docker-library/php/pull/542
    { \
        echo 'Package: php*'; \
        echo 'Pin: release *'; \
        echo 'Pin-Priority: -1'; \
    } > /etc/apt/preferences.d/no-debian-php; \
    \
# persistent / runtime deps
    apt-get update; \
    apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        $RUNTIME_DEPS \
        ca-certificates \
        curl \
        xz-utils \
    ; \
    \
    set -eux; \
    mkdir -p "$PHP_INI_DIR/conf.d"; \
# allow running as an arbitrary user (https://github.com/docker-library/php/issues/743)
    [ ! -d /var/www/html ]; \
    mkdir -p /var/www/html; \
    chown www-data:www-data /var/www/html; \
    chmod 777 /var/www/html; \
    \
# Set timezone to UTC by default
    ln -sf /usr/share/zoneinfo/Etc/UTC /etc/localtime; \
    \
# Use unicode
    locale-gen C.UTF-8 || true; \
    \
    export JQ_URL="https://circle-downloads.s3.amazonaws.com/circleci-images/cache/linux-amd64/jq-latest"; \
    curl --silent --show-error --location --fail --retry 3 --output /usr/bin/jq $JQ_URL; \
    chmod +x /usr/bin/jq; \
    jq --version; \
    \
    export COMPOSE_URL="https://circle-downloads.s3.amazonaws.com/circleci-images/cache/linux-amd64/docker-compose-latest"; \
    curl --silent --show-error --location --fail --retry 3 --output /usr/bin/docker-compose $COMPOSE_URL; \
    chmod +x /usr/bin/docker-compose; \
    docker-compose version; \
    \
    export DOCKERIZE_URL="https://circle-downloads.s3.amazonaws.com/circleci-images/cache/linux-amd64/dockerize-latest.tar.gz"; \
    curl --silent --show-error --location --fail --retry 3 --output /tmp/dockerize-linux-amd64.tar.gz $DOCKERIZE_URL; \
    tar -C /usr/local/bin -xzvf /tmp/dockerize-linux-amd64.tar.gz; \
    rm -rf /tmp/dockerize-linux-amd64.tar.gz; \
    dockerize --version; \
    \
    apt-get update; \
    apt-get install -y --no-install-recommends apache2 libonig-dev; \
    rm -rf /var/lib/apt/lists/*; \
    \
# generically convert lines like
#   export APACHE_RUN_USER=www-data
# into
#   : ${APACHE_RUN_USER:=www-data}
#   export APACHE_RUN_USER
# so that they can be overridden at runtime ("-e APACHE_RUN_USER=...")
    sed -ri 's/^export ([^=]+)=(.*)$/: ${\1:=\2}\nexport \1/' "$APACHE_ENVVARS"; \
    \
# setup directories and permissions
    . "$APACHE_ENVVARS"; \
    for dir in \
        "$APACHE_LOCK_DIR" \
        "$APACHE_RUN_DIR" \
        "$APACHE_LOG_DIR" \
    ; do \
        rm -rvf "$dir"; \
        mkdir -p "$dir"; \
        chown "$APACHE_RUN_USER:$APACHE_RUN_GROUP" "$dir"; \
# allow running as an arbitrary user (https://github.com/docker-library/php/issues/743)
        chmod 777 "$dir"; \
    done; \
    \
# delete the "index.html" that installing Apache drops in here
    rm -rvf /var/www/html/*; \
    \
# logs should go to stdout / stderr
    ln -sfT /dev/stderr "$APACHE_LOG_DIR/error.log"; \
    ln -sfT /dev/stdout "$APACHE_LOG_DIR/access.log"; \
    ln -sfT /dev/stdout "$APACHE_LOG_DIR/other_vhosts_access.log"; \
    chown -R --no-dereference "$APACHE_RUN_USER:$APACHE_RUN_GROUP" "$APACHE_LOG_DIR"; \
    \
# Apache + PHP requires preforking Apache for best results
    a2dismod mpm_event && a2enmod mpm_prefork ;\
# PHP files should be handled by PHP, and should be preferred over any other file type
    { \
        echo '<FilesMatch \.php$>'; \
        echo '\tSetHandler application/x-httpd-php'; \
        echo '</FilesMatch>'; \
        echo; \
        echo 'DirectoryIndex disabled'; \
        echo 'DirectoryIndex index.php index.html'; \
        echo; \
        echo '<Directory /var/www/>'; \
        echo '\tOptions -Indexes'; \
        echo '\tAllowOverride All'; \
        echo '</Directory>'; \
    } | tee "$APACHE_CONFDIR/conf-available/docker-php.conf" \
    && a2enconf docker-php ; \
    apt-get update; \
    apt-get install -y $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*; \
    groupadd --gid 3434 circleci; \
    useradd --uid 3434 --gid circleci --shell /bin/bash --create-home circleci; \
    echo 'circleci ALL=NOPASSWD: ALL' >> /etc/sudoers.d/50-circleci; \
    echo 'Defaults    env_keep += "DEBIAN_FRONTEND"' >> /etc/sudoers.d/env_keep;
ENV GPG_KEYS CBAF69F173A0FEA4B537F470D66C9593118BCCB6 F38252826ACD957EF380D39F2F7956BC5DA04B5D

FROM basic_system as php_source_v5_3
ENV PHP_VERSION 5.3.38
ENV PHP_URL="https://www.php.net/get/php-${PHP_VERSION}.tar.gz/from/this/mirror"

FROM basic_system as php_source_v5_4
ENV PHP_VERSION 5.4.45
ENV PHP_URL="https://www.php.net/get/php-${PHP_VERSION}.tar.gz/from/this/mirror"

FROM basic_system as php_source_v5_5
ENV PHP_VERSION 5.5.38
ENV PHP_URL="https://www.php.net/get/php-${PHP_VERSION}.tar.gz/from/this/mirror"

FROM basic_system as php_source_v5_6
ENV PHP_VERSION 5.6.40
ENV PHP_URL="https://www.php.net/get/php-${PHP_VERSION}.tar.gz/from/this/mirror"

FROM basic_system as php_source_v7_0
ENV PHP_VERSION 7.0.33
ENV PHP_URL="https://www.php.net/get/php-${PHP_VERSION}.tar.gz/from/this/mirror"

FROM basic_system as php_source_v7_1
ENV PHP_VERSION 7.1.30
ENV PHP_URL="https://www.php.net/get/php-${PHP_VERSION}.tar.gz/from/this/mirror"

FROM basic_system as php_source_v7_2
ENV PHP_VERSION 7.2.20
ENV PHP_URL="https://www.php.net/get/php-${PHP_VERSION}.tar.gz/from/this/mirror"

FROM basic_system as php_source_v7_3
ENV PHP_VERSION 7.3.6
ENV PHP_URL="https://www.php.net/get/php-${PHP_VERSION}.tar.gz/from/this/mirror"

FROM basic_system as php_source_v7_4
ENV PHP_VERSION 7.4.0beta4
ENV PHP_URL="https://downloads.php.net/~derick/php-${PHP_VERSION}.tar.gz"

FROM php_source_${VERSION} as php_source

ENV PHP_ASC_URL="" PHP_SHA256="" PHP_MD5=""

RUN set -eux; \
    \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends gnupg dirmngr; \
    rm -rf /var/lib/apt/lists/*; \
    \
    mkdir -p /usr/src; \
    cd /usr/src; \
    \
    curl -fsSL -o php.tar.gz "$PHP_URL"; \
    \
    if [ -n "$PHP_SHA256" ]; then \
        echo "$PHP_SHA256 *php.tar.gz" | sha256sum -c -; \
    fi; \
    if [ -n "$PHP_MD5" ]; then \
        echo "$PHP_MD5 *php.tar.gz" | md5sum -c -; \
    fi; \
    \
    if [ -n "$PHP_ASC_URL" ]; then \
        curl -fsSL -o php.tar.gz.asc "$PHP_ASC_URL"; \
        export GNUPGHOME="$(mktemp -d)"; \
        for key in $GPG_KEYS; do \
            gpg --batch --keyserver ha.pool.sks-keyservers.net --recv-keys "$key"; \
        done; \
        gpg --batch --verify php.tar.gz.asc php.tar.gz; \
        gpgconf --kill all; \
        rm -rf "$GNUPGHOME"; \
    fi; \
    \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false

COPY docker-php-source /usr/local/bin/
COPY docker-php-ext-*  /usr/local/bin/
ARG PHP_CONFIG_ARGS=""
ARG BASE_CONFIG_ARGS="--with-apxs2"
ENV PHP_CFLAGS="-fpic -fpie"
ENV PHP_LDFLAGS="-Wl,-O1 -Wl,--hash-style=both -pie"
ENV PHP_LIBS=""

# Version specific configure args
FROM php_source as config-v5_base
ENV PHP_EXTRA_CONFIGURE_ARGS ${BASE_CONFIG_ARGS} ${PHP_CONFIG_ARGS}
RUN set -xe; \
    { \
        echo deb http://httpredir.debian.org/debian jessie main ;\
        echo deb-src http://httpredir.debian.org/debian jessie main ;\
        echo ;\
        echo deb http://security.debian.org/ jessie/updates main ;\
        echo deb-src http://security.debian.org/ jessie/updates main ;\
    } | tee /etc/apt/sources.list.d/jessie.list ;\
    { \
        echo Package: openssl libssl-dev libssl-doc;\
        echo Pin: release a=oldoldstable ;\
        echo Pin-Priority: 600 ;\
    } | tee /etc/apt/preferences.d/openssl;\
    apt-get update; \
    apt-get install -y --no-install-recommends \
        openssl libssl-dev libssl-doc libcurl4-nss-dev; \
    rm -rf /var/lib/apt/lists/*

FROM php_source as config-v7_base
ENV PHP_EXTRA_CONFIGURE_ARGS ${BASE_CONFIG_ARGS} ${PHP_CONFIG_ARGS}

FROM config-v5_base as config-v5_3
FROM config-v5_base as config-v5_4
FROM config-v5_base as config-v5_5
FROM config-v5_base as config-v5_6


FROM config-v7_base as config-v7_0
FROM config-v7_base as config-v7_1

FROM php_source as config-v7_2
ENV PHP_EXTRA_CONFIGURE_ARGS ${BASE_CONFIG_ARGS} --with-password-argon2 --with-sodium=shared ${PHP_CONFIG_ARGS}

FROM php_source as config-v7_3
ENV PHP_EXTRA_CONFIGURE_ARGS ${BASE_CONFIG_ARGS} --with-password-argon2 --with-sodium=shared ${PHP_CONFIG_ARGS}

FROM php_source as config-v7_4
ENV PHP_EXTRA_CONFIGURE_ARGS ${BASE_CONFIG_ARGS} --with-password-argon2 --with-sodium=shared ${PHP_CONFIG_ARGS}

# Variant specific configure args

FROM config-$VERSION as config-variant-vanilla
ENV PHP_CFLAGS "-fstack-protector-strong -fpic -fpie -O2"

FROM config-$VERSION as config-variant-debug
ENV PHP_EXTRA_CONFIGURE_ARGS --enable-debug ${PHP_EXTRA_CONFIGURE_ARGS}

FROM config-variant-vanilla as config-variant-zts
ENV PHP_EXTRA_CONFIGURE_ARGS --enable-maintainer-zts ${PHP_EXTRA_CONFIGURE_ARGS}

FROM config-variant-debug as config-variant-debug-zts
ENV PHP_EXTRA_CONFIGURE_ARGS --enable-maintainer-zts ${PHP_EXTRA_CONFIGURE_ARGS}

FROM config-variant-vanilla as config-variant-asan
# don't detect leaks during image compilation
ENV LSAN_OPTIONS detect_leaks=0
ENV PHP_LIBS ${PHP_LIBS} -ldl -lasan
ENV PHP_CFLAGS ${PHP_CFLAGS} -fsanitize=address

FROM config-variant-debug as config-variant-debug-asan
# don't detect leaks during image compilation
ENV LSAN_OPTIONS detect_leaks=0
ENV PHP_LIBS ${PHP_LIBS} -ldl -lasan
ENV PHP_CFLAGS ${PHP_CFLAGS} -fsanitize=address

## Setup final image

FROM config-variant-${VARIANT} as php-build

ENV PHP_EXTRA_BUILD_DEPS apache2-dev

ENV PHP_CPPFLAGS="$PHP_CFLAGS"
ENV PHP_INI_DIR /usr/local/etc/php

RUN set -eux; \
    \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libedit-dev \
        libsodium-dev \
        libsqlite3-dev \
        libssl-dev \
        libxml2-dev \
        zlib1g-dev \
        libpq-dev \
        ${PHP_EXTRA_BUILD_DEPS:-}; \
    \
    apt-get update; \
    apt-get install -y --no-install-recommends libargon2-dev; \
    rm -rf /var/lib/apt/lists/*; \
    \
    export \
        CFLAGS="$PHP_CFLAGS" \
        CPPFLAGS="$PHP_CPPFLAGS" \
        LDFLAGS="$PHP_LDFLAGS"; \
        LIBS="$PHP_LIBS"; \
    \
    docker-php-source extract; \
    \
    cd /usr/src/php; \
    gnuArch="$(dpkg-architecture --query DEB_BUILD_GNU_TYPE)"; \
    debMultiarch="$(dpkg-architecture --query DEB_BUILD_MULTIARCH)"; \
# https://bugs.php.net/bug.php?id=74125
    if [ ! -d /usr/include/curl ]; then \
        ln -sT "/usr/include/$debMultiarch/curl" /usr/local/include/curl; \
    fi; \
    ./configure LIBS="$PHP_LIBS" \
        --build="$gnuArch" \
        --with-config-file-path="$PHP_INI_DIR" \
        --with-config-file-scan-dir="$PHP_INI_DIR/conf.d" \
        \
# make sure invalid --configure-flags are fatal errors intead of just warnings
        --enable-option-checking=fatal \
        \
# https://github.com/docker-library/php/issues/439
        --with-mhash \
        \
# --enable-ftp is included here because ftp_ssl_connect() needs ftp to be compiled statically (see https://github.com/docker-library/php/issues/236)
        --enable-ftp \
# --enable-mbstring is included here because otherwise there's no way to get pecl to use it properly (see https://github.com/docker-library/php/issues/195)
        --enable-mbstring \
# --enable-mysqlnd is included here because it's harder to compile after the fact than extensions are (since it's a plugin for several extensions, not an extension in itself)
        --enable-mysqlnd \
        \
        --with-curl \
        --with-libedit \
        --with-openssl \
        --with-zlib \
        --enable-fpm \
        --enable-cgi \
        --with-fpm-user=www-data \
        --with-fpm-group=www-data \
        --with-pear \
        --with-pdo-pgsql \
        --with-pdo-mysql \
        --with-mysqli \
        \
# bundled pcre does not support JIT on s390x
# https://manpages.debian.org/stretch/libpcre3-dev/pcrejit.3.en.html#AVAILABILITY_OF_JIT_SUPPORT
        $(test "$gnuArch" = 's390x-linux-gnu' && echo '--without-pcre-jit') \
        --with-libdir="lib/$debMultiarch" \
        \
        ${PHP_EXTRA_CONFIGURE_ARGS:-} \
    ; \
    make -j "$(nproc)"; \
    find -type f -name '*.a' -delete; \
    make install; \
    find /usr/local/bin /usr/local/sbin -type f -executable -exec strip --strip-all '{}' + || true; \
    make clean; \
    \
# https://github.com/docker-library/php/issues/692 (copy default example "php.ini" files somewhere easily discoverable)
    cp -v php.ini-* "$PHP_INI_DIR/"; \
    \
    cd /; \
    docker-php-source delete; \
    \
# reset apt-mark's "manual" list so that "purge --auto-remove" will remove all build dependencies
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark; \
    find /usr/local -type f -executable -exec ldd '{}' ';' \
        | awk '/=>/ { print $(NF-1) }' \
        | sort -u \
        | xargs -r dpkg-query --search \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual; \
    \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    \
# update pecl channel definitions https://github.com/docker-library/php/issues/443
    pecl update-channels; \
    rm -rf /tmp/pear ~/.pearrc; \
# smoke test
    php --version

FROM php-build AS final-v5_base
RUN set -xe; \
    [ -z ${PHP_VERSION##7.*} ] && exit 0 ;\
    (curl -q https://raw.githubusercontent.com/composer/getcomposer.org/76a7060ccb93902cd7576b67264ad91c8a2700e2/web/installer | php -- --filename=composer --install-dir=/usr/local/bin); \
    mkdir -p /tmp/memcached; \
    cd /tmp/memcached;\
    curl -L -o memcached.tar.gz https://github.com/php-memcached-dev/php-memcached/archive/2.2.0.tar.gz; \
    tar xf memcached.tar.gz --strip-component=1; \
    sudo apt-get update; \
    export EXT_DEPS="zlib1g-dev libmemcached-dev";\
    sudo apt-get -y install $EXT_DEPS; \
    phpize; ./configure --disable-memcached-sasl; \
    make install; \
    sudo apt-get remove $EXT_DEP; \
    cd ..; rm -rf memcached; \
    yes 'no' | pecl install mongo; \
    docker-php-ext-enable memcached; \
    docker-php-ext-enable mongo

FROM php-build AS final-v7_base

RUN set -xe; \
    [ -z ${PHP_VERSION##5.*} ] && exit 0 ;\
    (curl -q https://raw.githubusercontent.com/composer/getcomposer.org/76a7060ccb93902cd7576b67264ad91c8a2700e2/web/installer | php -- --filename=composer --install-dir=/usr/local/bin); \
    yes '' | pecl install apcu; \
    export EXT_DEPS="zlib1g-dev libmemcached-dev";\
    sudo apt-get update; \
    sudo apt-get -y install $EXT_DEPS; \
    yes 'no' | pecl install memcached; \
    docker-php-ext-enable memcached; \
    sudo apt-get remove $EXT_DEP; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    docker-php-ext-enable sodium 2> /dev/null || true

FROM final-v5_base AS v5_3-final
FROM final-v5_base AS v5_4-final
FROM final-v5_base AS v5_5-final
FROM final-v5_base AS v5_6-final
FROM final-v7_base AS v7_0-final
FROM final-v7_base AS v7_1-final
FROM final-v7_base AS v7_2-final
FROM final-v7_base AS v7_3-final
FROM final-v7_base AS v7_4-final

FROM ${VERSION}-final AS final

ENV LSAN_OPTIONS ""
WORKDIR /home/circleci
USER circleci

EXPOSE 80
