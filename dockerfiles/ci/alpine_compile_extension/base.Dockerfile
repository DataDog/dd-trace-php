FROM alpine:3.23

RUN mkdir -p /app
WORKDIR /app

# Break the dependencies into multiple layers to reduce individual layer size.
# This is just to avoid an upload issue I was hitting with large layers.
RUN set -eux; \
    apk add --no-cache \
        bash \
        autoconf \
        automake \
        catch2 \
        coreutils \
        curl \
        file \
        g++ \
        gcc \
        gmp-dev \
        libtool \
        linux-headers \
        make \
        cmake \
        build-base \
        curl-dev \
        libedit-dev \
        libffi-dev \
        libmcrypt-dev \
        librdkafka-dev \
        libsodium-dev \
        libxml2-dev \
        gnu-libiconv-dev \
        mpfr-dev \
        oniguruma-dev \
        python3 \
        tar \
        xz-dev \
        zlib-dev

# Profiling deps
# Minimum: libclang. Nice-to-have: full toolchain including linker to play
# with cross-language link-time optimization. Needs to match rustc -Vv's llvm
# version.
RUN apk add --no-cache llvm21-libs clang21-dev lld llvm21 rust-stdlib rust-src cargo clang git protoc unzip

RUN cargo install --force --locked bindgen-cli && mv /root/.cargo/bin/bindgen /usr/local/bin/ && rm -rf /root/.cargo

RUN set -eux; \
    mkdir -p /var/crash && chmod 1777 /var/crash; \
    GDB_VERSION="17.2"; \
    GDB_SHA256="cb891b9a9f554cac972eea5368176b240640ae90b681aae84bf873a9501f0063"; \
    cd /tmp; \
    curl -fsSL --retry 5 --retry-delay 2 --retry-connrefused -O "https://mirrors.kernel.org/sourceware/gdb/releases/gdb-${GDB_VERSION}.tar.gz"; \
    echo "${GDB_SHA256}  gdb-${GDB_VERSION}.tar.gz" | sha256sum -c -; \
    mkdir gdb && cd gdb; \
    tar -xf "../gdb-${GDB_VERSION}.tar.gz" --strip-components=1; \
    ./configure --disable-sim --disable-gdbserver --disable-tui; \
    make -j "$(nproc)"; \
    make install; \
    gdb --version; \
    cd / && rm -rf /tmp/gdb "/tmp/gdb-${GDB_VERSION}.tar.gz"

CMD ["bash"]
