FROM centos:7

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
    yum install -y devtoolset-7; \
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

# Required: CMake >= 3.0.2 (default version is 2.8.12.2)
# Required to build libzip from source (has to be a separate RUN layer)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh cmake https://github.com/Kitware/CMake/releases/download/v3.18.4/cmake-3.18.4.tar.gz; \
    cd "${SRC_DIR}/cmake"; \
    mkdir -v 'build' && cd 'build'; \
    ../bootstrap && make -j $(nproc) && make install; \
    cd - && rm -fr build

# Required: libzip >= 0.11 (default version is 0.9)
RUN source scl_source enable devtoolset-7; set -eux; \
    /root/download-src.sh libzip https://libzip.org/download/libzip-1.7.3.tar.gz; \
    cd "${SRC_DIR}/libzip"; \
    mkdir build && cd build; \
    cmake .. && make -j $(nproc) && make install;

ENV PKG_CONFIG_PATH="${PKG_CONFIG_PATH}:/usr/local/lib/pkgconfig:/usr/local/lib64/pkgconfig"

RUN printf "source scl_source enable devtoolset-7" | tee -a /etc/profile.d/zzz-ddtrace.sh /etc/bashrc
ENV BASH_ENV="/etc/profile.d/zzz-ddtrace.sh"

# Caution, takes a very long time! Since we have to build one from source,
# I picked LLVM 14, which matches Rust 1.60.
# Ordinarily we leave sources, but LLVM is 2GiB just for the sources...
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
  && git clone --depth 1 -b release/14.x https://github.com/llvm/llvm-project.git \
  && mkdir -vp llvm-project/build \
  && cd llvm-project/build \
  && cmake -G Ninja -DLLVM_ENABLE_PROJECTS=clang -DLLVM_TARGETS_TO_BUILD=host -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/usr/local ../llvm \
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

ARG PROTOBUF_C_VERSION="1.4.0"
ARG PROTOBUF_C_SHA256="26d98ee9bf18a6eba0d3f855ddec31dbe857667d269bc0b6017335572f85bbcb"
RUN source scl_source enable devtoolset-7 \
  && FILENAME=protobuf-c-${PROTOBUF_C_VERSION}.tar.gz \
  && curl -L  -O "https://github.com/protobuf-c/protobuf-c/releases/download/v${PROTOBUF_C_VERSION}/${FILENAME}" \
  && tar --no-same-owner -xf "$FILENAME" \
  && cd ${FILENAME%.tar.gz} \
  && ./configure --with-pic --disable-shared --enable-static --prefix=/usr/local --libdir=/usr/local/lib64 \
  && make -j $(nproc) \
  && make install \
  && cd - \
  && rm -fr "$FILENAME" "${FILENAME%.tar.gz}"

# rust sha256sum generated locally after verifying it with sha256
ARG RUST_VERSION="1.60.0"
ARG RUST_SHA256_ARM="99c419c2f35d4324446481c39402c7baecd7a8baed7edca9f8d6bbd33c05550c"
ARG RUST_SHA256_X86="b8a4c3959367d053825e31f90a5eb86418eb0d80cacda52bfa80b078e18150d5"
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

ENV PATH="/rust/cargo/bin:${PATH}"

# now install PHP specific dependencies
RUN set -eux; \
    rpm -Uvh https://dl.fedoraproject.org/pub/epel/epel-release-latest-7.noarch.rpm; \
    yum update -y; \
    yum install -y \
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

RUN echo '#define SECBIT_NO_SETUID_FIXUP (1 << 2)' > '/usr/include/linux/securebits.h'
