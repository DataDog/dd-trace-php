FROM alpine:3.17

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
        cmake \
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
        librdkafka-dev \
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
        re2c \
        sqlite-dev \
        sudo \
        tar \
        valgrind \
        vim \
        xz \
        zlib-dev \
    ;

RUN set -eux; \
# Add user/group for circleci
    addgroup -g 3434 -S circleci; \
    adduser -u 3434 -D -S -G circleci -G wheel circleci --shell /bin/bash; \
    sed -e 's/# %wheel ALL=(ALL) NOPASSWD: ALL/%wheel ALL=(ALL) NOPASSWD: ALL/g' -i /etc/sudoers; \
    adduser circleci wheel; \
# Fix "sudo: setrlimit(RLIMIT_CORE): Operation not permitted" error
    echo "Set disable_coredump false" >> /etc/sudo.conf; \
# Add www-data user
    adduser -u 82 -D -S -G www-data www-data; \
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
    export DOCKER_VERSION=$(curl --silent --fail --retry 3 "https://download.docker.com/linux/static/stable/$(uname -m)/" | grep -o -e 'docker-[.0-9]*-ce\.tgz' | sort -r | head -n 1); \
    DOCKER_URL="https://download.docker.com/linux/static/stable/x86_64/${DOCKER_VERSION}"; \
    curl --silent --show-error --location --fail --retry 3 --output /tmp/docker.tgz "${DOCKER_URL}"; \
    ls -lha /tmp/docker.tgz; \
    tar -xz -C /tmp -f /tmp/docker.tgz; \
    mv /tmp/docker/* /usr/bin; \
    rm -rf /tmp/docker /tmp/docker.tgz; \
    which docker; \
    (docker version || true); \
# Install docker-compose
    curl -L "https://github.com/docker/compose/releases/download/v2.17.3/docker-compose-linux-$(uname -m)" -o /usr/local/bin/docker-compose; \
    chmod +x /usr/local/bin/docker-compose; \
    which docker-compose; \
    (docker compose --version || true);

# symlink cmake
RUN version=$(cmake --version | awk '/cmake version/ {print $3}') \
    && mkdir -vp "/opt/cmake/$version/bin" \
    && cd "/opt/cmake/$version/bin" \
    && ln -s $(command -v cmake) cmake

# catch2
ARG CATCH2_VERSION="2.13.10"
ARG CATCH2_SHA256="d54a712b7b1d7708bc7a819a8e6e47b2fde9536f487b89ccbca295072a7d9943"
RUN cd /tmp \
    && curl -o catch2.tar.gz -L https://github.com/catchorg/Catch2/archive/refs/tags/v${CATCH2_VERSION}.tar.gz \
    && (echo "${CATCH2_SHA256}  catch2.tar.gz" | sha256sum -c -) \
    && mkdir catch2 \
    && cd catch2 \
    && tar -xf ../catch2.tar.gz --strip 1 \
    && cmake -Bbuild -H. -DBUILD_TESTING=OFF -DCMAKE_INSTALL_PREFIX=/opt/catch2 \
    && cmake --build build/ --target install \
    && cd /tmp \
    && rm -fr catch2

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
