FROM centos:6

# Letsencrpypt root certificate was crossigned by DST Root CA X3 which expired on 2021-09-30, we now need our own cert
#   - details    : https://letsencrypt.org/docs/dst-root-ca-x3-expiration-september-2021/
#   - x1 root    : https://letsencrypt.org/certs/isrgrootx1.pem
#   - x3 expired : Copied from old container
COPY isrgrootx1.pem /etc/pki/ca-trust/source/anchors/
COPY dst-x3-expired.pem /etc/pki/ca-trust/source/blacklist/
RUN update-ca-trust && update-ca-trust force-enable

COPY CentOS-Base.repo /etc/yum.repos.d/

RUN set -eux; \
    echo 'ip_resolve = IPv4' >>/etc/yum.conf; \
    yum update -y; \
    yum install -y \
        centos-release-scl \
        curl \
        environment-modules \
        gcc \
        gcc-c++ \
        git \
        libedit-devel \
        make \
        openssl-devel \
        pkg-config \
        postgresql-devel \
        readline-devel \
        scl-utils \
        unzip \
        vim \
        xz \
        zlib-devel;

COPY CentOS-SCLo-scl.repo /etc/yum.repos.d/
COPY CentOS-SCLo-scl-rh.repo /etc/yum.repos.d/

RUN set -eux; \
    yum install -y devtoolset-7; \
    yum clean all;

ENV SRC_DIR=/usr/local/src

COPY download-src.sh /root/
RUN set -eux; \
# version 1.0.2 of openssl (default version is 1.0.1) required for letsencrypt certificates to continue working
    /root/download-src.sh openssl https://www.openssl.org/source/old/1.0.2/openssl-1.0.2u.tar.gz; \
    cd "${SRC_DIR}/openssl"; \
    CFLAGS=-fPIC ./config shared --prefix=/usr --openssldir=/etc/pki/tls && make && make install; \
# Latest version of m4 required
    /root/download-src.sh m4 https://ftp.gnu.org/gnu/m4/m4-1.4.18.tar.gz; \
    cd "${SRC_DIR}/m4"; \
    ./configure && make && make install; \
# Latest version of autoconf required
    /root/download-src.sh autoconf https://ftp.gnu.org/gnu/autoconf/autoconf-2.69.tar.gz; \
    cd "${SRC_DIR}/autoconf"; \
    ./configure && make && make install; \
# Required: libxml >= 2.9.0 (default version is 2.7.6)
    /root/download-src.sh libxml2 http://xmlsoft.org/sources/libxml2-2.9.10.tar.gz; \
    cd "${SRC_DIR}/libxml2"; \
    ./configure --with-python=no; \
    make && make install; \
# Required: libcurl >= 7.29.0 (default version is 7.19.7)
    /root/download-src.sh libcurl https://curl.haxx.se/download/curl-7.72.0.tar.gz; \
    cd "${SRC_DIR}/libcurl"; \
    ./configure && make && make install; \
# Required: libffi >= 3.0.11 (default version is 3.0.5)
    /root/download-src.sh libffi https://github.com/libffi/libffi/releases/download/v3.4.2/libffi-3.4.2.tar.gz; \
    cd "${SRC_DIR}/libffi"; \
    ./configure && make && make install; \
# Required: oniguruma (not installed by deafult)
    /root/download-src.sh oniguruma https://github.com/kkos/oniguruma/releases/download/v6.9.5_rev1/onig-6.9.5-rev1.tar.gz; \
    cd "${SRC_DIR}/oniguruma"; \
    ./configure && make && make install; \
# Required: bison >= 3.0.0 (not installed by deafult)
    /root/download-src.sh bison https://ftp.gnu.org/gnu/bison/bison-3.7.3.tar.gz; \
    cd "${SRC_DIR}/bison"; \
    ./configure && make && make install; \
# Required: re2c >= 0.13.4 (not installed by deafult)
    /root/download-src.sh re2c https://github.com/skvadrik/re2c/releases/download/2.0.3/re2c-2.0.3.tar.xz; \
    cd "${SRC_DIR}/re2c"; \
    ./configure && make && make install;

# Required: CMake >= 3.0.2 (default version is 2.8.12.2)
# Required to build libzip from source (has to be a separate RUN layer)
RUN source scl_source enable devtoolset-7; \
    set -eux; \
    /root/download-src.sh cmake https://github.com/Kitware/CMake/releases/download/v3.18.4/cmake-3.18.4.tar.gz; \
    cd "${SRC_DIR}/cmake"; \
    ./bootstrap && make && make install; \
# Required: libzip >= 0.11 (default version is 0.9)
    /root/download-src.sh libzip https://libzip.org/download/libzip-1.7.3.tar.gz; \
    cd "${SRC_DIR}/libzip"; \
    mkdir build && cd build; \
    cmake .. && make && make install;

RUN echo '#define SECBIT_NO_SETUID_FIXUP (1 << 2)' > '/usr/include/linux/securebits.h'

ENV PKG_CONFIG_PATH="${PKG_CONFIG_PATH}:/usr/local/lib/pkgconfig:/usr/local/lib64/pkgconfig"

ENV PHP_SRC_DIR=/usr/local/src/php
ENV PHP_INSTALL_DIR=/opt/php

RUN printf "source scl_source enable devtoolset-7" | tee -a /etc/profile.d/zzz-ddtrace.sh /etc/bashrc
ENV BASH_ENV="/etc/profile.d/zzz-ddtrace.sh"

COPY switch-php /usr/local/bin/
