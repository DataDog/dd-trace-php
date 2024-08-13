FROM alpine:3.18

RUN mkdir -p /app
WORKDIR /app

# Break the dependencies into multiple layers to reduce individual layer size.
# This is just to avoid an upload issue I was hitting with large layers.
RUN set -eux; \
    apk add --no-cache \
        bash \
        autoconf \
        coreutils \
        g++ \
        gcc \
        make \
        build-base \
        curl-dev \
        libedit-dev \
        libffi-dev \
        libmcrypt-dev \
        libsodium-dev \
        libxml2-dev \
        gnu-libiconv-dev \
        oniguruma-dev \
        tar

# Profiling deps
# Minimum: libclang. Nice-to-have: full toolchain including linker to play
# with cross-language link-time optimization. Needs to match rustc -Vv's llvm
# version.
RUN apk add --no-cache llvm16-libs clang16-dev lld llvm16 rust-stdlib cargo clang git protoc unzip

CMD ["bash"]
