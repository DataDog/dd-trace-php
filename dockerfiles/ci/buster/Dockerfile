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
# JSON parser
    export JQ_URL="https://circle-downloads.s3.amazonaws.com/circleci-images/cache/linux-amd64/jq-latest"; \
    curl --silent --show-error --location --fail --retry 3 --output /usr/bin/jq $JQ_URL; \
    chmod +x /usr/bin/jq; \
    jq --version; \
    \
# Docker
    export COMPOSE_URL="https://circle-downloads.s3.amazonaws.com/circleci-images/cache/linux-amd64/docker-compose-latest"; \
    curl --silent --show-error --location --fail --retry 3 --output /usr/bin/docker-compose $COMPOSE_URL; \
    chmod +x /usr/bin/docker-compose; \
    docker-compose version; \
    \
    export DOCKERIZE_URL="https://circle-downloads.s3.amazonaws.com/circleci-images/cache/linux-amd64/dockerize-latest.tar.gz"; \
    curl --silent --show-error --location --fail --retry 3 --output /tmp/dockerize-linux-amd64.tar.gz $DOCKERIZE_URL; \
    tar -C /usr/local/bin -xzvf /tmp/dockerize-linux-amd64.tar.gz; \
    rm -rf /tmp/dockerize-linux-amd64.tar.gz; \
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

COPY switch-php /usr/local/bin/

WORKDIR /home/circleci

# Override stop signal to stop process gracefully
# https://github.com/php/php-src/blob/17baa87faddc2550def3ae7314236826bc1b1398/sapi/fpm/php-fpm.8.in#L163
STOPSIGNAL SIGQUIT

EXPOSE 9000
EXPOSE 80
