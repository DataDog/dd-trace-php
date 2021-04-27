FROM alpine:3.11

ENV PHP_SRC_DIR=/usr/local/src/php
ENV PHP_INSTALL_DIR=/opt/php

RUN set -eux; \
# Install deps
    apk add --no-cache \
        argon2-dev \
        autoconf \
        bison \
        bash \
        ca-certificates \
        coreutils \
        curl \
        curl-dev \
        dpkg \
        dpkg-dev \
        file \
        g++ \
        gcc \
        gdb \
        git \
        gnupg \
        libc-dev \
        libedit-dev \
        libffi-dev \
        libmemcached \
        libmemcached-dev \
        libsodium-dev \
        libxml2-dev \
        libzip-dev \
        linux-headers \
        make \
        oniguruma-dev \
        openssh \
        openssl \
        openssl-dev \
        postgresql-dev \
        pkgconf \
        py-pip \
        python-dev \
        re2c \
        sqlite-dev \
        sudo \
        tar \
        valgrind \
        vim \
        xz \
        zlib-dev \
    ; \
# Add user/group for circleci
    addgroup -g 3434 -S circleci; \
    adduser -u 3434 -D -S -G circleci -G wheel circleci; \
    sed -e 's/# %wheel ALL=(ALL) NOPASSWD: ALL/%wheel ALL=(ALL) NOPASSWD: ALL/g' -i /etc/sudoers; \
    adduser circleci wheel; \
# Fix "sudo: setrlimit(RLIMIT_CORE): Operation not permitted" error
    echo "Set disable_coredump false" >> /etc/sudo.conf; \
# Add www-data user
    addgroup -g 82 -S www-data; \
    adduser -u 82 -D -S -G www-data www-data; \
# 82 is the standard uid/gid for "www-data" in Alpine
# https://git.alpinelinux.org/aports/tree/main/apache2/apache2.pre-install?h=3.9-stable
# allow running as an arbitrary user (https://github.com/docker-library/php/issues/743)
    [ ! -d /var/www/html ]; \
    mkdir -p /var/www/html; \
    chown www-data:www-data /var/www/html; \
    chmod 777 /var/www/html; \
# Share welcome message with the world
    echo '[ ! -z "$TERM" -a -r /etc/motd ] && cat /etc/motd' \
        >> /etc/bash.bashrc; \
# Setup php source directory
    mkdir -p $PHP_SRC_DIR; \
    chown -R circleci:circleci /usr/local/src; \
# Setup php install directory
    mkdir -p $PHP_INSTALL_DIR; \
    chown -R circleci:circleci /opt; \
# Install Docker
    export DOCKER_VERSION=$(curl --silent --fail --retry 3 https://download.docker.com/linux/static/stable/x86_64/ | grep -o -e 'docker-[.0-9]*-ce\.tgz' | sort -r | head -n 1); \
    DOCKER_URL="https://download.docker.com/linux/static/stable/x86_64/${DOCKER_VERSION}"; \
    curl --silent --show-error --location --fail --retry 3 --output /tmp/docker.tgz "${DOCKER_URL}"; \
    ls -lha /tmp/docker.tgz; \
    tar -xz -C /tmp -f /tmp/docker.tgz; \
    mv /tmp/docker/* /usr/bin; \
    rm -rf /tmp/docker /tmp/docker.tgz; \
    which docker; \
    (docker version || true); \
# Install docker-compose
    curl -L "https://github.com/docker/compose/releases/download/1.25.0/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose; \
    chmod +x /usr/local/bin/docker-compose; \
    which docker-compose; \
    (docker-compose --version || true); \
# Install dockerize
    DOCKERIZE_URL="https://circle-downloads.s3.amazonaws.com/circleci-images/cache/linux-amd64/dockerize-latest.tar.gz"; \
    curl --silent --show-error --location --fail --retry 3 --output /tmp/dockerize-linux-amd64.tar.gz $DOCKERIZE_URL; \
    tar -C /usr/local/bin -xzvf /tmp/dockerize-linux-amd64.tar.gz; \
    rm -rf /tmp/dockerize-linux-amd64.tar.gz; \
    dockerize --version;

# Run everything else as circleci user
USER circleci

RUN set -eux; \
# Pretty prompt
    echo "PS1='\[\033[01;32m\]\u\[\033[00m\]\[\033[00;32m\](alpine)\[\033[00m\]:\[\033[01;34m\]\w\[\033[00m\]\$ '" | \
        tee -a /home/circleci/.bashrc; \
# Handy aliases
    echo "alias ll='ls -al'" | \
        tee -a /home/circleci/.bashrc; \
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
