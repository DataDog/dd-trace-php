FROM debian:buster AS base

ENV LANG=C.UTF-8
ENV DEBIAN_FRONTEND=noninteractive

ENV RUNTIME_DEPS \
    apache2 \
    apache2-dev \
    ca-certificates \
    clang-format \
    curl \
    debian-goodies \
    gdb \
    git \
    less \
    libcurl4-openssl-dev \
    libedit-dev \
    libmcrypt-dev \
    libmemcached-dev \
    libonig-dev \
    libpq-dev \
    libsodium-dev \
    libsqlite3-dev \
    libssl-dev \
    libxml2-dev \
    libzip-dev \
    netbase \
    nginx \
    sudo \
    unzip \
    valgrind \
    vim \
    xz-utils \
    zip \
    zlib1g-dev

ENV PHPIZE_DEPS \
    autoconf \
    bison \
    dpkg-dev \
    file \
    g++ \
    gcc \
    libc-dev \
    make \
    pkg-config \
    re2c

RUN set -eux; \
# Set timezone to UTC by default
    ln -sf /usr/share/zoneinfo/Etc/UTC /etc/localtime; \
    \
# Use unicode
    locale-gen C.UTF-8 || true; \
    \
# Core Dumps
    ulimit -c unlimited; \
    \
# Ensure debug symbols are available
    echo "deb http://deb.debian.org/debian-debug/ buster-debug main" | \
        tee -a /etc/apt/sources.list; \
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
        $RUNTIME_DEPS; \
    \
# circleci user + sudo
    groupadd --gid 3434 circleci; \
        useradd --uid 3434 --gid circleci --shell /bin/bash --create-home circleci; \
        echo 'circleci ALL=NOPASSWD: ALL' >> /etc/sudoers.d/50-circleci; \
        echo 'Defaults    env_keep += "DEBIAN_FRONTEND"' >> /etc/sudoers.d/env_keep; \
    \
# Allow nginx to be run as non-root for tests
    chown -R circleci:circleci /var/log/nginx/ /var/lib/nginx/; \
# Install CMake
    CMAKE_VERSION="3.20.1"; \
    CMAKE_SHA256="b8c141bd7a6d335600ab0a8a35e75af79f95b837f736456b5532f4d717f20a09"; \
    cd /tmp && curl -OL https://github.com/Kitware/CMake/releases/download/v${CMAKE_VERSION}/cmake-${CMAKE_VERSION}-Linux-x86_64.tar.gz; \
    (echo "${CMAKE_SHA256} cmake-${CMAKE_VERSION}-Linux-x86_64.tar.gz" | sha256sum -c -); \
    mkdir -p /opt/cmake/${CMAKE_VERSION}; \
    tar --strip-components 1 -C /opt/cmake/${CMAKE_VERSION} -xf /tmp/cmake-${CMAKE_VERSION}-Linux-x86_64.tar.gz; \
# Install Catch2
    CATCH2_VERSION="2.13.5"; \
    CATCH2_SHA256="7fee7d643599d10680bfd482799709f14ed282a8b7db82f54ec75ec9af32fa76"; \
    cd /tmp && curl -OL https://github.com/catchorg/Catch2/archive/v${CATCH2_VERSION}.tar.gz; \
    (echo "${CATCH2_SHA256} v${CATCH2_VERSION}.tar.gz" | sha256sum -c -); \
    mkdir catch2 && cd catch2; \
    tar -xf ../v${CATCH2_VERSION}.tar.gz --strip 1; \
    /opt/cmake/${CMAKE_VERSION}/bin/cmake -Bbuild -H. -DBUILD_TESTING=OFF -DCMAKE_INSTALL_PREFIX=/opt/catch2; \
    /opt/cmake/${CMAKE_VERSION}/bin/cmake --build build/ --target install; \
# Install lcov
    LCOV_VERSION="1.15"; \
    LCOV_SHA256="c1cda2fa33bec9aa2c2c73c87226cfe97de0831887176b45ee523c5e30f8053a"; \
    cd /tmp && curl -OL https://github.com/linux-test-project/lcov/releases/download/v${LCOV_VERSION}/lcov-${LCOV_VERSION}.tar.gz; \
    (echo "${LCOV_SHA256} lcov-${LCOV_VERSION}.tar.gz" | sha256sum -c -); \
    mkdir lcov && cd lcov; \
    tar -xf ../lcov-${LCOV_VERSION}.tar.gz --strip 1; \
    make install; \
    lcov --version;\
# Docker
    DOCKERIZE_VERSION="0.6.1"; \
    DOCKERIZE_SHA256="1fa29cd41a5854fd5423e242f3ea9737a50a8c3bcf852c9e62b9eb02c6ccd370"; \
    curl --silent --show-error --location --fail --retry 3 \
        --output /tmp/dockerize-linux-amd64-v${DOCKERIZE_VERSION}.tar.gz \
        https://github.com/jwilder/dockerize/releases/download/v${DOCKERIZE_VERSION}/dockerize-linux-amd64-v${DOCKERIZE_VERSION}.tar.gz; \
    (echo "${DOCKERIZE_SHA256} /tmp/dockerize-linux-amd64-v${DOCKERIZE_VERSION}.tar.gz" | sha256sum -c -); \
    tar -C /usr/local/bin -xzvf /tmp/dockerize-linux-amd64-v${DOCKERIZE_VERSION}.tar.gz; \
    rm -rf /tmp/dockerize-linux-amd64-v${DOCKERIZE_VERSION}.tar.gz; \
    dockerize --version;

# Apache config
ENV APACHE_CONFDIR /etc/apache2
ENV APACHE_ENVVARS $APACHE_CONFDIR/envvars

RUN set -eux; \
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
    && a2enconf docker-php;

RUN set -eux; \
# Share welcome message with the world
    echo '[ ! -z "$TERM" -a -r /etc/motd ] && cat /etc/motd' \
        >> /etc/bash.bashrc;

# Set up PHP directories
ENV PHP_SRC_DIR=/usr/local/src/php
ENV PHP_INSTALL_DIR=/opt/php

RUN set -eux; \
# Setup php source directory
    mkdir -p $PHP_SRC_DIR; \
    chown -R circleci:circleci /usr/local/src; \
# Setup php install directory
    mkdir -p $PHP_INSTALL_DIR; \
    chown -R circleci:circleci /opt;

# Run everything else as circleci user
USER circleci

RUN set -eux; \
# Pretty prompt
    echo "PS1='\[\033[01;32m\]\u\[\033[00m\]\[\033[00;35m\](buster)\[\033[00m\]:\[\033[01;34m\]\w\[\033[00m\]\$ '" | \
        tee -a /home/circleci/.bashrc; \
# Handy aliases
    echo "alias ll='ls -al'" | \
        tee -a /home/circleci/.bash_aliases; \
# Please remember gdb history
    echo 'set history save on' >> /home/circleci/.gdbinit; \
        chmod 600 /home/circleci/.gdbinit;

COPY install-ext-from-source /usr/local/bin/install-ext-from-source
COPY switch-php /usr/local/bin/

WORKDIR /home/circleci

# Override stop signal to stop process gracefully
# https://github.com/php/php-src/blob/17baa87faddc2550def3ae7314236826bc1b1398/sapi/fpm/php-fpm.8.in#L163
STOPSIGNAL SIGQUIT

EXPOSE 9000
EXPOSE 80

CMD [ "bash" ]
