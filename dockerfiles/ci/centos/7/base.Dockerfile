FROM centos:7

RUN set -eux; \
# Fix yum config, as centos 7 is EOL and mirrorlist.centos.org does not resolve anymore
# https://serverfault.com/a/1161847
    sed -i s/mirror.centos.org/vault.centos.org/g /etc/yum.repos.d/*.repo; \
    sed -i s/^#.*baseurl=http/baseurl=http/g /etc/yum.repos.d/*.repo; \
    sed -i s/^mirrorlist=http/#mirrorlist=http/g /etc/yum.repos.d/*.repo; \
    echo 'ip_resolve = IPv4' >>/etc/yum.conf; \
# kernel and linux-firmware are useless in containers (host kernel is used);
# excluding them globally prevents ~183 MB of waste from being pulled in as side-effects.
    echo 'exclude=kernel-core* kernel-modules* linux-firmware' >>/etc/yum.conf; \
    yum update -y; \
    yum install -y \
        centos-release-scl \
        curl \
        environment-modules \
        gcc \
        gcc-c++ \
        git \
        help2man \
        libcurl-devel \
        libedit-devel \
        make \
        openssl-devel \
# data dumper needed for autoconf, apparently
        perl-Data-Dumper \
        pkg-config \
        scl-utils \
        unzip \
        vim \
        xz; \
# package centos-release-scl installs new yum repos, we must fix them too
    sed -i s/mirror.centos.org/buildlogs.centos.org/g /etc/yum.repos.d/CentOS-SCLo-*.repo; \
    sed -i s/^#.*baseurl=http/baseurl=http/g /etc/yum.repos.d/CentOS-SCLo-*.repo; \
    sed -i s/^mirrorlist=http/#mirrorlist=http/g /etc/yum.repos.d/CentOS-SCLo-*.repo; \
    yum update nss nss-util nss-sysinit nss-tools; \
    yum install -y --nogpgcheck devtoolset-7; \
    yum clean all;

ENV SRC_DIR=/usr/local/src

COPY download-src.sh /root/

# Latest version of m4 required
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh m4 https://mirrors.kernel.org/gnu/m4/m4-1.4.18.tar.gz; \
    cd "${SRC_DIR}/m4"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure && make -j $(nproc) && make install; \
    cd - && rm -fr build "${SRC_DIR}/m4"

# Latest version of autoconf required
RUN set -eux; \
    /root/download-src.sh autoconf https://mirrors.kernel.org/gnu/autoconf/autoconf-2.69.tar.gz; \
    cd "${SRC_DIR}/autoconf"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure && make -j $(nproc) && make install; \
    cd - && rm -fr build "${SRC_DIR}/autoconf"

# Automake required
RUN set -eux; \
    /root/download-src.sh automake https://mirrors.kernel.org/gnu/automake/automake-1.13.4.tar.gz; \
    cd "${SRC_DIR}/automake"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure && make -j $(nproc) && make install; \
    cd - && rm -fr build "${SRC_DIR}/automake"

# Libtool required
RUN set -eux; \
    /root/download-src.sh libtool https://mirrors.kernel.org/gnu/libtool/libtool-2.5.4.tar.gz; \
    cd "${SRC_DIR}/libtool"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure && make -j $(nproc) && make install; \
    cd - && rm -fr build "${SRC_DIR}/libtool"

# Required: libxml >= 2.9.0 (default version is 2.7.6)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh libxml2 http://xmlsoft.org/sources/libxml2-2.9.10.tar.gz; \
    cd "${SRC_DIR}/libxml2"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure --with-python=no --disable-static && make -j $(nproc) && make install; \
    cd - && rm -fr build

# Required: libffi >= 3.0.11 (default version is 3.0.5)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh libffi https://github.com/libffi/libffi/releases/download/v3.4.2/libffi-3.4.2.tar.gz; \
    cd "${SRC_DIR}/libffi"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure --disable-static && make -j $(nproc) && make install; \
    cd - && rm -fr build

# Required: oniguruma (not installed by default)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh oniguruma https://github.com/kkos/oniguruma/releases/download/v6.9.5_rev1/onig-6.9.5-rev1.tar.gz; \
    cd "${SRC_DIR}/oniguruma"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure --disable-static && make -j $(nproc) && make install; \
    cd - && rm -fr build

# Required: bison >= 3.0.0 (not installed by default)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh bison https://mirrors.kernel.org/gnu/bison/bison-3.7.3.tar.gz; \
    cd "${SRC_DIR}/bison"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure && make -j $(nproc) && make install; \
    cd - && rm -fr build "${SRC_DIR}/bison"

# Required: re2c >= 0.13.4 (not installed by default)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh re2c https://github.com/skvadrik/re2c/releases/download/2.0.3/re2c-2.0.3.tar.xz; \
    cd "${SRC_DIR}/re2c"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure && make -j $(nproc) && make install; \
    cd - && rm -fr build "${SRC_DIR}/re2c"

# Required: CMake >= 3.20.0 (default version is 2.8.12.2)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh cmake https://github.com/Kitware/CMake/releases/download/v3.28.6/cmake-3.28.6.tar.gz; \
    cd "${SRC_DIR}/cmake"; \
    mkdir -v 'build' && cd 'build'; \
    ../bootstrap -- -DBUILD_CursesDialog=OFF && make -j $(nproc) && make install; \
    cd - && rm -fr build "${SRC_DIR}/cmake" \
    && rm -f /usr/local/bin/cpack \
    && rm -rf /usr/local/share/cmake-*/Help /usr/local/share/doc/cmake* /usr/local/share/man/man1/cmake*

# PHP 8.4+ requires OpenSSL >= 1.1.1
RUN source scl_source enable devtoolset-7; set -ex; \
    /root/download-src.sh openssl https://github.com/openssl/openssl/releases/download/OpenSSL_1_1_1w/openssl-1.1.1w.tar.gz; \
    cd "${SRC_DIR}/openssl"; \
    mkdir -v 'build' && cd 'build'; \
    ../config --prefix=/usr/local/openssl --openssldir=/usr/local/openssl shared zlib; \
    make -j $(nproc) && make install; \
    rm -f /usr/local/openssl/lib/*.a; \
    echo "export PATH=/usr/local/openssl/bin:\$PATH" > /etc/profile.d/openssl.sh; \
    echo "export LD_LIBRARY_PATH=/usr/local/openssl/lib:\$LD_LIBRARY_PATH" >> /etc/profile.d/openssl.sh; \
    source /etc/profile.d/openssl.sh; \
    openssl version; \
    cd - && rm -fr build

# PHP 8.4 requires zlib >= 1.2.11
RUN source scl_source enable devtoolset-7; set -ex; \
    /root/download-src.sh zlib https://zlib.net/fossils/zlib-1.2.11.tar.gz; \
    cd "${SRC_DIR}/zlib"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure --prefix=/usr/local/zlib; \
    make -j $(nproc) && make install; \
    rm -f /usr/local/zlib/lib/*.a; \
    cd - && rm -fr build

RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh libzip https://libzip.org/download/libzip-1.10.1.tar.gz; \
    cd "${SRC_DIR}/libzip"; \
    rm -rf build && mkdir build && cd build; \
    cmake .. \
      -DCMAKE_INSTALL_PREFIX=/usr/local \
      -DBUILD_SHARED_LIBS=ON \
      -DENABLE_OPENSSL=ON \
      -DCMAKE_PREFIX_PATH="/usr/local/openssl;/usr/local/zlib" \
      -DOpenSSL_ROOT=/usr/local/openssl \
      -DZLIB_ROOT=/usr/local/zlib \
      -DCMAKE_POLICY_DEFAULT_CMP0074=NEW \
      -DCMAKE_INSTALL_RPATH=/usr/local/openssl/lib; \
    make -j $(nproc) && make install; \
    ldconfig; \
    cd - && rm -fr build

# PHP 8.4 requires curl >= 7.61.0 (link it to OpenSSL 1.1.1 we just built)
RUN source scl_source enable devtoolset-7; set -ex; \
    /root/download-src.sh curl https://curl.se/download/curl-7.61.1.tar.gz; \
    cd "${SRC_DIR}/curl"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure --prefix=/usr/local/curl --with-ssl=/usr/local/openssl --disable-static; \
    make -j $(nproc) && make install; \
    cd - && rm -fr build

# PHP 8.4 requires sqlite3 >= 3.43
RUN source scl_source enable devtoolset-7; set -ex; \
    /root/download-src.sh sqlite3 https://www.sqlite.org/2024/sqlite-autoconf-3460000.tar.gz; \
    cd "${SRC_DIR}/sqlite3"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure --prefix=/usr/local/sqlite3 --disable-static; \
    make -j $(nproc) && make install; \
    cd - && rm -fr build

ENV PKG_CONFIG_PATH="${PKG_CONFIG_PATH}:/usr/local/lib/pkgconfig:/usr/local/lib64/pkgconfig:/usr/local/openssl/lib/pkgconfig:/usr/local/zlib/lib/pkgconfig:/usr/local/curl/lib/pkgconfig:/usr/local/sqlite3/lib/pkgconfig"

# now install PHP specific dependencies
RUN set -eux; \
    yum install -y epel-release; \
    yum update -y; \
    yum install -y \
    re2c \
    bzip2-devel \
    httpd-devel \
    libmemcached-devel \
    libsodium-devel \
    libsqlite3x-devel \
    libxml2-devel \
    libxslt-devel \
    postgresql-devel \
    readline-devel \
    zlib-devel; \
    yum clean all;

RUN printf "source scl_source enable devtoolset-7\n" | tee -a /etc/profile.d/zzz-ddtrace.sh /etc/bashrc
ENV BASH_ENV="/etc/profile.d/zzz-ddtrace.sh"

ENV LD_LIBRARY_PATH="/usr/local/openssl/lib:${LD_LIBRARY_PATH}"
