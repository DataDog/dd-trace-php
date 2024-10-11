FROM centos:7

RUN set -eux; \
# Fix yum config, as centos 7 is EOL and mirrorlist.centos.org does not resolve anymore
# https://serverfault.com/a/1161847
    sed -i s/mirror.centos.org/vault.centos.org/g /etc/yum.repos.d/*.repo; \
    sed -i s/^#.*baseurl=http/baseurl=http/g /etc/yum.repos.d/*.repo; \
    sed -i s/^mirrorlist=http/#mirrorlist=http/g /etc/yum.repos.d/*.repo; \
    echo 'ip_resolve = IPv4' >>/etc/yum.conf; \
    yum update -y; \
    yum install -y \
        centos-release-scl \
        curl \
        environment-modules \
        gcc \
        gcc-c++ \
        git \
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
    /root/download-src.sh m4 https://ftp.gnu.org/gnu/m4/m4-1.4.18.tar.gz; \
    cd "${SRC_DIR}/m4"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure && make -j $(nproc) && make install; \
    cd - && rm -fr build

# Latest version of autoconf required
RUN set -eux; \
    /root/download-src.sh autoconf https://ftp.gnu.org/gnu/autoconf/autoconf-2.69.tar.gz; \
    cd "${SRC_DIR}/autoconf"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure && make -j $(nproc) && make install; \
    cd - && rm -fr build

# Required: libxml >= 2.9.0 (default version is 2.7.6)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh libxml2 http://xmlsoft.org/sources/libxml2-2.9.10.tar.gz; \
    cd "${SRC_DIR}/libxml2"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure --with-python=no && make -j $(nproc) && make install; \
    cd - && rm -fr build

# Required: libffi >= 3.0.11 (default version is 3.0.5)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh libffi https://github.com/libffi/libffi/releases/download/v3.4.2/libffi-3.4.2.tar.gz; \
    cd "${SRC_DIR}/libffi"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure && make -j $(nproc) && make install; \
    cd - && rm -fr build

# Required: oniguruma (not installed by deafult)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh oniguruma https://github.com/kkos/oniguruma/releases/download/v6.9.5_rev1/onig-6.9.5-rev1.tar.gz; \
    cd "${SRC_DIR}/oniguruma"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure && make -j $(nproc) && make install; \
    cd - && rm -fr build

# Required: bison >= 3.0.0 (not installed by deafult)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh bison https://ftp.gnu.org/gnu/bison/bison-3.7.3.tar.gz; \
    cd "${SRC_DIR}/bison"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure && make -j $(nproc) && make install; \
    cd - && rm -fr build

# Required: re2c >= 0.13.4 (not installed by deafult)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh re2c https://github.com/skvadrik/re2c/releases/download/2.0.3/re2c-2.0.3.tar.xz; \
    cd "${SRC_DIR}/re2c"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure && make -j $(nproc) && make install; \
    cd - && rm -fr build

# Required: CMake >= 3.20.0 (default version is 2.8.12.2)
# Required to build libzip from source (has to be a separate RUN layer)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh cmake https://github.com/Kitware/CMake/releases/download/v3.30.5/cmake-3.30.5.tar.gz; \
    cd "${SRC_DIR}/cmake"; \
    mkdir -v 'build' && cd 'build'; \
    ../bootstrap && make -j $(nproc) && make install; \
    cd - && rm -fr build

# Required: libzip >= 0.11 (default version is 0.9)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh libzip https://libzip.org/download/libzip-1.7.3.tar.gz; \
    cd "${SRC_DIR}/libzip"; \
    mkdir build && cd build; \
    cmake .. && make -j $(nproc) && make install; \
    cd - && rm -fr build

# PHP 8.4 requires OpenSSL >= 1.1.1
RUN source scl_source enable devtoolset-7; set -ex; \
    /root/download-src.sh openssl https://openssl.org/source/old/1.1.1/openssl-1.1.1w.tar.gz; \
    cd "${SRC_DIR}/openssl"; \
    mkdir -v 'build' && cd 'build'; \
    ../config --prefix=/usr/local/openssl --openssldir=/usr/local/openssl shared zlib; \
    make -j $(nproc) && make install; \
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
    cd - && rm -fr build

# PHP 8.4 requires curl >= 7.61.0
RUN source scl_source enable devtoolset-7; set -ex; \
    /root/download-src.sh curl https://curl.se/download/curl-7.61.1.tar.gz; \
    cd "${SRC_DIR}/curl"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure --prefix=/usr/local/curl; \
    make -j $(nproc) && make install; \
    cd - && rm -fr build

# PHP 8.4 requires sqlite3 >= 3.43
RUN source scl_source enable devtoolset-7; set -ex; \
    /root/download-src.sh sqlite3 https://www.sqlite.org/2024/sqlite-autoconf-3460000.tar.gz; \
    cd "${SRC_DIR}/sqlite3"; \
    mkdir -v 'build' && cd 'build'; \
    ../configure --prefix=/usr/local/sqlite3; \
    make -j $(nproc) && make install; \
    cd - && rm -fr build

ENV PKG_CONFIG_PATH="${PKG_CONFIG_PATH}:/usr/local/lib/pkgconfig:/usr/local/lib64/pkgconfig:/usr/local/openssl/lib/pkgconfig:/usr/local/zlib/lib/pkgconfig:/usr/local/curl/lib/pkgconfig:/usr/local/sqlite3/lib/pkgconfig"

# Caution, takes a very long time! Since we have to build one from source,
# I picked LLVM 17, which matches Rust 1.76.
# Ordinarily we leave sources, but LLVM is 2GiB just for the sources...
# Minimum: libclang. Nice-to-have: full toolchain including linker to play
# with cross-language link-time optimization. Needs to match rustc -Vv's llvm
# version.
RUN source scl_source enable devtoolset-7 \
  && yum install -y python3 \
  && /root/download-src.sh ninja https://github.com/ninja-build/ninja/archive/refs/tags/v1.11.0.tar.gz \
  && mkdir -vp "${SRC_DIR}/ninja/build" \
  && cd "${SRC_DIR}/ninja/build" \
  && ../configure.py --bootstrap --verbose \
  && strip ninja \
  && mv -v ninja /usr/local/bin/ \
  && cd - \
  && rm -fr "${SRC_DIR}/ninja" \
  && cd /usr/local/src \
  && git clone --depth 1 -b release/17.x https://github.com/llvm/llvm-project.git \
  && mkdir -vp llvm-project/build \
  && cd llvm-project/build \
  && cmake -G Ninja -DLLVM_ENABLE_PROJECTS="clang;lld" -DLLVM_TARGETS_TO_BUILD=host -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/usr/local ../llvm \
  && cmake --build . --parallel $(nproc) --target "install/strip" \
  && rm -f /usr/local/lib/libclang*.a /usr/local/lib/libLLVM*.a \
  && cd - \
  && rm -fr llvm-project \
  && yum remove -y python3 \
  && yum clean all

ARG PROTOBUF_VERSION="3.19.4"
ARG PROTOBUF_SHA256="89ac31a93832e204db6d73b1e80f39f142d5747b290f17340adce5be5b122f94"
RUN source scl_source enable devtoolset-7 \
  && FILENAME=protobuf-cpp-${PROTOBUF_VERSION}.tar.gz \
  && cd /usr/local/src \
  && curl -L -O "https://github.com/protocolbuffers/protobuf/releases/download/v${PROTOBUF_VERSION}/${FILENAME}" \
  && tar --no-same-owner -xf "$FILENAME" \
  && cd protobuf-${PROTOBUF_VERSION} \
  && ./configure \
    --prefix=/usr/local \
    --libdir=/usr/local/lib64 \
    --with-pic \
    --disable-shared \
    --enable-static \
  && make -j $(nproc) \
  && make install \
  && cd - \
  && rm -fr "$FILENAME" "${FILENAME%.tar.gz}" "protobuf-${PROTOBUF_VERSION}"

# rust sha256sum generated locally after verifying it with sha256
ARG RUST_VERSION="1.76.0"
ARG RUST_SHA256_ARM="2e8313421e8fb673efdf356cdfdd4bc16516f2610d4f6faa01327983104c05a0"
ARG RUST_SHA256_X86="9d589d2036b503cc45ecc94992d616fb3deec074deb36cacc2f5c212408f7399"
# Mount a cache into /rust/cargo if you want to pre-fetch packages or something
ENV CARGO_HOME=/rust/cargo
ENV RUSTUP_HOME=/rust/rustup
RUN source scl_source enable devtoolset-7 \
    && mkdir -p -v "${CARGO_HOME}" "${RUSTUP_HOME}" \
    && chown -R 777 "${CARGO_HOME}" "${RUSTUP_HOME}" \
    && MARCH=$(uname -m) \
    && if [[ $MARCH == "x86_64" ]]; then RUST_SHA256=${RUST_SHA256_X86};\
     elif [[ $MARCH == "aarch64" ]];then RUST_SHA256=${RUST_SHA256_ARM}; fi && \
    FILENAME=rust-${RUST_VERSION}-${MARCH}-unknown-linux-gnu.tar.gz && \
    curl -L --write-out '%{http_code}' -O https://static.rust-lang.org/dist/${FILENAME} && \
    printf '%s  %s' "$RUST_SHA256" "$FILENAME" | sha256sum --check --status && \
    tar -xf "$FILENAME" \
    && cd ${FILENAME%.tar.gz} \
    && ./install.sh --components="rustc,cargo,clippy-preview,rustfmt-preview,rust-std-${MARCH}-unknown-linux-gnu" \
    && cd - \
    && rm -fr "$FILENAME" "${FILENAME%.tar.gz}"

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

RUN printf "source scl_source enable devtoolset-7" | tee -a /etc/profile.d/zzz-ddtrace.sh /etc/bashrc
ENV BASH_ENV="/etc/profile.d/zzz-ddtrace.sh"

ENV PATH="/rust/cargo/bin:${PATH}"

RUN echo '#define SECBIT_NO_SETUID_FIXUP (1 << 2)' > '/usr/include/linux/securebits.h'
