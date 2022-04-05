FROM centos:7 AS base

RUN set -eux; \
    yum groupinstall -y "Development tools"; \
    yum install -y \
        libxml2-devel \
        openssl-devel \
        libcurl-devel \
        libffi-devel \
        libedit-devel; \
    rpm -Uvh https://dl.fedoraproject.org/pub/epel/epel-release-latest-7.noarch.rpm; \
    yum install -y oniguruma-devel; \
    yum clean all

RUN set -eux; \
    yum install -y centos-release-scl; \
    yum-config-manager --enable rhel-server-rhscl-7-rpms; \
    yum install -y devtoolset-7; \
    yum clean all;

ENV PKG_CONFIG_PATH="${PKG_CONFIG_PATH}:/usr/local/lib/pkgconfig:/usr/local/lib64/pkgconfig"

ENV SRC_DIR=/usr/local/src
COPY download-src.sh /root/

# Required: CMake >= 3.0.2 (default version is 2.8.12.2) in order to build libzip
RUN source scl_source enable devtoolset-7; \
    set -eux; \
    /root/download-src.sh cmake https://github.com/Kitware/CMake/releases/download/v3.18.4/cmake-3.18.4.tar.gz; \
    cd "${SRC_DIR}/cmake"; \
    ./bootstrap; \
    make; \
    make install;

# libzip from centos:7 repos is < than the required minimum 0.11
RUN source scl_source enable devtoolset-7; \
    set -eux; \
    /root/download-src.sh libzip https://libzip.org/download/libzip-1.7.3.tar.gz; \
    cd "${SRC_DIR}/libzip"; \
    mkdir build; \
    cd build; \
    cmake ..; \
    make; \
    make install;

COPY switch-php /usr/local/bin/

FROM base AS build

ARG phpVersion
ARG phpTarGzUrl
ARG phpSha256Hash

ENV PHP_SRC_DIR=/usr/local/src/php
ENV PHP_INSTALL_DIR=/opt/php/${phpVersion}

RUN source scl_source enable devtoolset-7; \
    set -eux; \
    mkdir -p $PHP_SRC_DIR; \
    mkdir -p $PHP_INSTALL_DIR; \
    curl -fsSL -o /tmp/php.tar.gz "${phpTarGzUrl}"; \
    (echo "${phpSha256Hash}  /tmp/php.tar.gz" | sha256sum -c -); \
    tar xf /tmp/php.tar.gz -C "${PHP_SRC_DIR}" --strip-components=1; \
    rm -f /tmp/php.tar.gz;

COPY php-${phpVersion}/configure.sh /root/configure.sh

# ENV SRC_DIR=/usr/local/src
# COPY download-src.sh /root/

# # ENV PKG_CONFIG_PATH=/usr/local/lib64/pkgconfig

# # # Required: CMake >= 3.0.2 (default version is 2.8.12.2) in order to build libzip
# # RUN /root/download-src.sh cmake https://github.com/Kitware/CMake/releases/download/v3.18.4/cmake-3.18.4.tar.gz \
# #     && cd "${SRC_DIR}/cmake" \
# #     && ./bootstrap \
# #     && make \
# #     && make install

# # # libzip from centos:7 repos is < than the required minimum 0.11
# # RUN /root/download-src.sh libzip https://libzip.org/download/libzip-1.7.3.tar.gz \
# #     && cd "${SRC_DIR}/libzip" \
# #     && mkdir build \
# #     && cd build \
# #     && cmake .. \
# #     && make \
# #     && make install

RUN source scl_source enable devtoolset-7; \
    set -eux; \
    mkdir -p /tmp/build-php && cd /tmp/build-php; \
    /root/configure.sh \
        --prefix=${PHP_INSTALL_DIR} \
        --with-config-file-path=${PHP_INSTALL_DIR} \
        --with-config-file-scan-dir=${PHP_INSTALL_DIR}/conf.d;

RUN source scl_source enable devtoolset-7; \
    set -eux; \
    cd /tmp/build-php; \
    make -j "$((`nproc`+1))"; \
    make install; \
    mkdir -p ${PHP_INSTALL_DIR}/conf.d;

# # # ENV PKG_CONFIG_PATH="${PKG_CONFIG_PATH}:/usr/local/lib/pkgconfig:/usr/local/lib64/pkgconfig"


# # ENV PHP_VERSION=${phpVersion}


FROM base AS php

ARG phpVersion
ENV PHP_INSTALL_DIR=/opt/php/${phpVersion}

# COPY --from=php-zts $PHP_INSTALL_DIR_ZTS $PHP_INSTALL_DIR_ZTS
# COPY --from=php-debug $PHP_INSTALL_DIR_DEBUG_NTS $PHP_INSTALL_DIR_DEBUG_NTS
COPY --from=build $PHP_INSTALL_DIR $PHP_INSTALL_DIR

RUN set -eux; \
# Set the default PHP version
    switch-php ${phpVersion};
