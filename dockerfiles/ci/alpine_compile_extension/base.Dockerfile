FROM alpine:3.22

RUN mkdir -p /app
WORKDIR /app

# Break the dependencies into multiple layers to reduce individual layer size.
# This is just to avoid an upload issue I was hitting with large layers.
RUN set -eux; \
    apk add --no-cache \
        bash \
        autoconf \
        catch2 \
        coreutils \
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
        oniguruma-dev \
        python3 \
        tar

# Profiling deps
# Minimum: libclang. Nice-to-have: full toolchain including linker to play
# with cross-language link-time optimization. Needs to match rustc -Vv's llvm
# version.
RUN apk add --no-cache llvm19-libs clang19-dev lld19 llvm19 rust-stdlib cargo clang19 git protoc unzip

RUN cargo install --force --locked bindgen-cli && mv /root/.cargo/bin/bindgen /usr/local/bin/ && rm -rf /root/.cargo

CMD ["bash"]
