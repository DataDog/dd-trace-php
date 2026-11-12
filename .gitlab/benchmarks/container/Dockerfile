FROM registry.ddbuild.io/images/benchmarking-platform-tools-ubuntu:latest

ARG CI_JOB_TOKEN
ARG RUST_VERSION=1.91.1

# Install MySQL & create a test database (User: test, Password: test, Database: test)
RUN --mount=type=ssh bp-install mysql && apt-get install --no-install-recommends -y mysql-client

# Install ddprof & the agent for ddprof submission
RUN --mount=type=ssh bp-install ddprof datadog-agent

# Install sirun to run performance tests
RUN curl -o sirun.tar.gz -L https://github.com/DataDog/sirun/releases/download/v0.1.10/sirun-v0.1.10-x86_64-unknown-linux-musl.tar.gz \
    && tar -xzf sirun.tar.gz \
    && rm sirun.tar.gz \
    && mv sirun /usr/bin/sirun

# Get an LLVM toolchain for cross-language LTO:
# https://doc.rust-lang.org/rustc/linker-plugin-lto.html
RUN apt-get update \
    && apt-get install --no-install-recommends -y \
        apt-transport-https \
        ca-certificates \
        lsb-release \
        software-properties-common \
        gnupg \
        gpg \
    && (wget --no-check-certificate -O - https://apt.llvm.org/llvm-snapshot.gpg.key | apt-key add -) \
    && add-apt-repository "deb http://apt.llvm.org/focal/ llvm-toolchain-focal-16 main" \
    && apt-get install --no-install-recommends -y \
        clang-16 \
        libclang-16-dev \
        lld-16 \
        llvm-16-dev

# Dependencies for building PHP extensions
RUN apt-get update \
    && apt-get install --no-install-recommends -y \
        libcurl4-gnutls-dev \
        libsodium-dev \
        libargon2-0-dev

# Get an assortment of tools including PHP and Rust
RUN apt-get update \
    && add-apt-repository ppa:ondrej/php \
    && sed -i 's/main$/main main\/debug/' /etc/apt/sources.list.d/ondrej* \
    && apt-get update \
    && apt-get install --no-install-recommends -y \
        sudo \
        git \
        hwinfo \
        jq \
        nginx \
        php8.2 \
        php8.2-dev \
        php8.2-common-dbgsym \
        php8.2-cli-dbgsym \
        php8.2-cgi \
        php8.2-cgi-dbgsym \
        php8.2-curl \
        php8.2-dom \
        php8.2-fpm \
        php8.2-iconv \
        php8.2-mbstring \
        php8.2-mysql \
        php8.2-xml \
        php8.2-zip \
        libphp8.2-embed \
        valgrind \
        xz-utils \
    && curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain ${RUST_VERSION} --profile minimal

RUN apt-get install -y php-pear

RUN iniDir=$(php -i | awk -F"=> " '/Scan this dir for additional .ini files/ {print $2}') \
    && pecl install redis-5.3.7 \
    && echo "extension=redis.so" > $iniDir/redis.ini

RUN extDir=$(php -r 'print ini_get("extension_dir");') \
    && ln -s $extDir/redis.so $extDir/redis-5.3.7.so

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install Redis 5.0 from source
RUN apt-get install --no-install-recommends -y \
        build-essential \
        tcl \
    && curl -o redis.tar.gz -L http://download.redis.io/releases/redis-5.0.10.tar.gz \
    && tar -xzf redis.tar.gz \
    && rm redis.tar.gz \
    && cd redis-5.0.10 \
    && make \
    && sudo make install \
    && cd .. \
    && rm -rf redis-5.0.10

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

ENV PATH=/root/.cargo/bin:$PATH
ENV DD_PROFILING_OUTPUT_PPROF=/tmp/foo.pprof
WORKDIR /app
