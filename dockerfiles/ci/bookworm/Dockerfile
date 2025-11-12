FROM debian:bookworm AS base

ENV LANG=C.UTF-8
ENV DEBIAN_FRONTEND=noninteractive
ENV ACCEPT_EULA=Y

# For LLVM dependencies:
# Minimum: libclang. Nice-to-have: full toolchain including linker to play
# with cross-language link-time optimization. Needs to match rustc -Vv's llvm
# version.
ENV DEVLIBS \
    libclang-17-dev \
    libclang-rt-17-dev \
    llvm-17-dev \
    lld-17 \
    libbrotli-dev \
    libcurl4-openssl-dev \
    libedit-dev \
    libffi-dev \
    libfreetype6-dev \
    libicu-dev \
    libjpeg-dev \
    libtool \
    libmcrypt-dev \
    libmemcached-dev \
    libodbc1 \
    libonig-dev \
    libpq-dev \
    libpng-dev \
    librabbitmq-dev \
    librdkafka-dev \
    libsodium-dev \
    libsqlite3-dev \
    libssl-dev \
    libltdl-dev \
    libwebp-dev \
    libxml2-dev \
    libxslt1-dev \
    libzip-dev \
    libc6-dbg \
    odbcinst1debian2 \
    zlib1g-dev \
    libasan6 \
    unixodbc-dev \
    unixodbc

ENV RUNTIME_DEPS \
    apache2 \
    apache2-dev \
    ca-certificates \
    clang-format-17 \
    clang-tidy-17 \
    curl \
    debian-goodies \
    git \
    gnupg \
    jq \
    less \
    lsof \
    netbase \
    netcat-openbsd \
    nginx \
    strace \
    sudo \
    unzip \
    vim \
    xz-utils \
    zip

ENV PHPIZE_DEPS \
    autoconf \
    bison \
    clang-17 \
    cmake \
    dpkg-dev \
    file \
    gnupg \
    gpg \
    file \
    libc-dev \
    make \
    pkg-config \
    re2c

ARG TARGETPLATFORM
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
    echo "deb http://deb.debian.org/debian-debug/ bookworm-debug main" | \
        tee -a /etc/apt/sources.list; \
    \
# Use LLVM from orig vendor (also LLVM 17 is not shipped with bookworm)
    apt-get update; \
    apt-get install -y curl gnupg software-properties-common; \
    curl https://apt.llvm.org/llvm-snapshot.gpg.key | tee /etc/apt/trusted.gpg.d/apt.llvm.org.asc; \
    add-apt-repository "deb http://apt.llvm.org/bookworm/ llvm-toolchain-bookworm-17 main"; \
    add-apt-repository "deb-src http://apt.llvm.org/bookworm/ llvm-toolchain-bookworm-17 main"; \
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
        gcc-$(if [ "$TARGETPLATFORM" != "linux/arm64" ]; then echo aarch64; else echo x86-64; fi)-linux-gnu \
        g++-$(if [ "$TARGETPLATFORM" != "linux/arm64" ]; then echo aarch64; else echo x86-64; fi)-linux-gnu \
        libc6-dev-$(if [ "$TARGETPLATFORM" != "linux/arm64" ]; then echo arm64; else echo amd64; fi)-cross \
        $DEVLIBS \
        $PHPIZE_DEPS \
        $RUNTIME_DEPS; \
    \
# Disable DST ROOT X3 certificate explicitly to fix conflicts with older openssl versions \
    sed -ri 's/(mozilla\/DST_Root_CA_X3.crt)/!\1/' /etc/ca-certificates.conf; \
    update-ca-certificates; \
    \
# circleci user + sudo
    groupadd --gid 3434 circleci; \
        useradd --uid 3434 --gid circleci --shell /bin/bash --create-home circleci; \
        echo 'circleci ALL=NOPASSWD: ALL' >> /etc/sudoers.d/50-circleci; \
        echo 'Defaults    env_keep += "DEBIAN_FRONTEND"' >> /etc/sudoers.d/env_keep; \
    \
# Allow nginx to be run as non-root for tests
    chown -R circleci:circleci /var/log/nginx/ /var/lib/nginx/; \
    \
# Make clang the default compiler
    update-alternatives --install /usr/bin/cc cc /usr/bin/clang-17 100; \
    update-alternatives --install /usr/bin/c++ c++ /usr/bin/clang++-17 100; \
    update-alternatives --install /usr/bin/clang clang /usr/bin/clang-17 100; \
    update-alternatives --install /usr/bin/clang++ clang++ /usr/bin/clang++-17 100; \
    update-alternatives --install /usr/bin/ld ld /usr/bin/ld.lld-17 100; \
    echo "-L /usr/lib/llvm-17/lib/clang/17/lib/linux" > /usr/lib/llvm-17/bin/clang.cfg; \
# Include libasan library path
    echo /usr/lib/llvm-17/lib/clang/17/lib/linux > /etc/ld.so.conf.d/libasan.conf && ldconfig

ENV CMAKE_VERSION="3.24.4"

# Install CMake
RUN set -eux; \
    if [ "$(uname -m)" = "aarch64" ]; then \
        CMAKE_SHA256="86f823f2636bf715af89da10e04daa476755a799d451baee66247846e95d7bee"; \
    else \
        CMAKE_SHA256="cac77d28fb8668c179ac02c283b058aeb846fe2133a57d40b503711281ed9f19"; \
    fi; \
    cd /tmp && curl -L --output cmake.tar.gz https://github.com/Kitware/CMake/releases/download/v${CMAKE_VERSION}/cmake-${CMAKE_VERSION}-Linux-$(uname -m).tar.gz; \
    (echo "${CMAKE_SHA256} cmake.tar.gz" | sha256sum -c -); \
    mkdir -p /opt/cmake/${CMAKE_VERSION}; \
    tar --strip-components 1 -C /opt/cmake/${CMAKE_VERSION} -xf /tmp/cmake.tar.gz; \
# Currently there's only one version of cmake, make it default
    ln -s /opt/cmake/${CMAKE_VERSION}/bin/cmake /usr/local/bin/cmake; \
    rm -rfv /tmp/*

# Install Catch2
RUN set -eux; \
    CATCH2_VERSION="2.13.10"; \
    CATCH2_SHA256="d54a712b7b1d7708bc7a819a8e6e47b2fde9536f487b89ccbca295072a7d9943"; \
    cd /tmp && curl -OL https://github.com/catchorg/Catch2/archive/v${CATCH2_VERSION}.tar.gz; \
    (echo "${CATCH2_SHA256}  v${CATCH2_VERSION}.tar.gz" | sha256sum -c -); \
    mkdir catch2 && cd catch2; \
    tar -xf ../v${CATCH2_VERSION}.tar.gz --strip 1; \
    /opt/cmake/${CMAKE_VERSION}/bin/cmake -Bbuild -H. -DBUILD_TESTING=OFF -DCMAKE_INSTALL_PREFIX=/opt/catch2 -DCATCH_BUILD_STATIC_LIBRARY=ON; \
    /opt/cmake/${CMAKE_VERSION}/bin/cmake --build build/ --target install; \
    rm -rfv /tmp/*

# Install lcov
RUN set -eux; \
    LCOV_VERSION="1.15"; \
    LCOV_SHA256="c1cda2fa33bec9aa2c2c73c87226cfe97de0831887176b45ee523c5e30f8053a"; \
    cd /tmp && curl -OL https://github.com/linux-test-project/lcov/releases/download/v${LCOV_VERSION}/lcov-${LCOV_VERSION}.tar.gz; \
    (echo "${LCOV_SHA256} lcov-${LCOV_VERSION}.tar.gz" | sha256sum -c -); \
    mkdir lcov && cd lcov; \
    tar -xf ../lcov-${LCOV_VERSION}.tar.gz --strip 1; \
    make install; \
    lcov --version; \
    rm -rfv /tmp/*

# Install gdb
RUN set -eux; \
    apt install -y libmpfr-dev libgmp-dev; \
    GDB_VERSION="15.2"; \
    GDB_SHA256="9d16bc2539a2a20dc3ef99b48b8414d51c51305c8577eb7a1da00996f6dea223";\
    cd /tmp && curl -OL https://mirrors.kernel.org/sourceware/gdb/releases/gdb-${GDB_VERSION}.tar.gz; \
    (echo "${GDB_SHA256} gdb-${GDB_VERSION}.tar.gz" | sha256sum -c -); \
    mkdir gdb && cd gdb; \
    tar -xf ../gdb-${GDB_VERSION}.tar.gz --strip 1;\
    ./configure; \
    make -j "$((`nproc`+1))"; \
    make install

# Install valgrind
RUN set -eux; \
    VALGRIND_VERSION="3.25.1"; \
    VALGRIND_SHA256="61deb8d0727b45c268efdc1b3b6c9e679cd97cbf5ee4b28d1dead7c8b7a271af"; \
    cd /tmp && curl -OL https://mirrors.kernel.org/sourceware/valgrind/valgrind-${VALGRIND_VERSION}.tar.bz2; \
    (echo "${VALGRIND_SHA256}" valgrind-${VALGRIND_VERSION}.tar.bz2 | sha256sum -c -); \
    mkdir valgrind && cd valgrind; \
    tar -xjf ../valgrind-${VALGRIND_VERSION}.tar.bz2 --strip 1;\
    ./configure; \
    make -j "$((`nproc`+1))"; \
    make install

# Install SqlServer PHP Driver
# https://learn.microsoft.com/en-us/sql/connect/odbc/linux-mac/installing-the-microsoft-odbc-driver-for-sql-server
RUN set -eux; \
    curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg; \
    curl https://packages.microsoft.com/config/debian/12/prod.list | sudo tee /etc/apt/sources.list.d/mssql-release.list; \
    apt-get update; \
    apt-get install -y \
      msodbcsql18 \
      mssql-tools18 \
      $(if [ "$TARGETPLATFORM" = "linux/amd64" ]; then echo msodbcsql17 mssql-tools; fi);

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
# disable config depending on env var
    a2disconf other-vhosts-access-log.conf; \
    \
# logs should go to stdout / stderr
    ln -sfT /dev/stderr "$APACHE_LOG_DIR/error.log"; \
    ln -sfT /dev/stdout "$APACHE_LOG_DIR/access.log"; \
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
    } | tee "$APACHE_CONFDIR/conf-available/docker-php.conf"; \
    a2enconf docker-php; \
# We want to use mod_rewrite
    a2enmod rewrite;

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

# rust sha256sum generated locally after verifying it with sha256
ARG RUST_VERSION="1.84.1"
ARG RUST_NIGHTLY_VERSION="-2025-06-13"
# Mount a cache into /rust/cargo if you want to pre-fetch packages or something
ENV CARGO_HOME=/rust/cargo
ENV RUSTUP_HOME=/rust/rustup
RUN mkdir -p -v "${CARGO_HOME}" "${RUSTUP_HOME}" \
    && chmod -R 777 "${CARGO_HOME}" "${RUSTUP_HOME}" \
    && MARCH=$(uname -m) \
    && curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain $RUST_VERSION -c "rustc,cargo,clippy-preview,rustfmt-preview,rust-std,rust-src" \
    && export PATH="/rust/cargo/bin:$PATH" \
    && rustup install nightly${RUST_NIGHTLY_VERSION} \
    && rustup component add rust-src --toolchain nightly${RUST_NIGHTLY_VERSION}-$MARCH-unknown-linux-gnu

ENV PATH="/rust/cargo/bin:${PATH}"

ARG GO_VERSION="1.22.1"
ARG GO_SHA256_ARM="e56685a245b6a0c592fc4a55f0b7803af5b3f827aaa29feab1f40e491acf35b8"
ARG GO_SHA256_X86="aab8e15785c997ae20f9c88422ee35d962c4562212bb0f879d052a35c8307c7f"
ENV GOROOT=/go
RUN MARCH=$(uname -m) \
    && GO_SHA256=$(if [ "$MARCH" = "x86_64" ]; then echo ${GO_SHA256_X86}; elif [ "$MARCH" = "aarch64" ]; then echo ${GO_SHA256_ARM}; fi) \
    && FILENAME=go${GO_VERSION}.linux-$(if [ "$MARCH" = "x86_64" ]; then echo amd64; elif [ "$MARCH" = "aarch64" ]; then echo arm64; fi).tar.gz \
    && curl -L --write-out '%{http_code}' -O https://go.dev/dl/${FILENAME} \
    && printf '%s  %s' "$GO_SHA256" "$FILENAME" | sha256sum --check --status \
    && tar -xf "$FILENAME" -C / \
    && rm "${FILENAME}"

ENV PATH="/go/bin:${PATH}"

# Add the wait script to the image: note SHA 672a28f0509433e3b4b9bcd4d9cd7668cea7e31a has been reviewed and should not
# be changed without an appropriate code review.
ADD https://raw.githubusercontent.com/eficode/wait-for/672a28f0509433e3b4b9bcd4d9cd7668cea7e31a/wait-for /usr/bin/wait-for
RUN chmod a+rx /usr/bin/wait-for

# Run everything else as circleci user
USER circleci

RUN set -eux; \
# Pretty prompt
    echo "PS1='\[\033[01;32m\]\u\[\033[00m\]\[\033[00;35m\](bookworm)\[\033[00m\]:\[\033[01;34m\]\w\[\033[00m\]\$ '" | \
        tee -a /home/circleci/.bashrc; \
# Autocomplete of Makefile targets (see: https://stackoverflow.com/a/38415982)
    echo "complete -W \"\\\`grep -oE '^[a-zA-Z0-9_.-]+:([^=]|$)' ?akefile | sed 's/[^a-zA-Z0-9_.-]*$//'\\\`\" make" | \
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
