FROM php:8.5-fpm-alpine

# doubles as php/toolchain image for musl

RUN apk add --no-cache \
    abuild autoconf automake bash bison clang clang-dev cmake curl curl-dev \
    build-base g++ gcc gdb git libc-dev linux-headers llvm22-dev llvm22-static make \
    musl-dev nginx oniguruma-dev openssl-dev patch patchelf pkgconf re2c libtool \
    libxml2-dev libzip-dev python3 samurai xz xz-static zlib-dev \
    vim


RUN adduser -D -u 1000 linux_user

ENV CARGO_HOME=/usr/local/cargo \
    RUSTUP_HOME=/usr/local/rustup \
    PATH="/usr/local/cargo/bin:${PATH}" \
    LIBCLANG_PATH=/usr/lib

RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | \
    sh -s -- -y --default-toolchain 1.87.0 --no-modify-path && \
    chmod -R 777 /usr/local/cargo /usr/local/rustup && \
    ln -sf /usr/local/cargo/bin/{cargo,rustc} /usr/local/bin/ && \
    rustup toolchain install nightly-$(uname -m)-unknown-linux-musl && \
    rustup component add rust-src --toolchain nightly-$(uname -m)-unknown-linux-musl

COPY llvm-runtimes /tmp/llvm-runtimes
RUN RUST_TARGET="$(uname -m)-unknown-linux-musl" && \
    RUST_TOOLCHAIN="/usr/local/rustup/toolchains/nightly-${RUST_TARGET}" && \
    apk update && \
    cd /tmp/llvm-runtimes && \
    abuild-keygen -an && \
    cp /root/.abuild/*.rsa.pub /etc/apk/keys/ && \
    abuild -F -r && \
    package="$(find /root/packages -name 'llvm-libunwind-static-patched-*.apk' -print -quit)" && \
    apk add --allow-untrusted "$package" && \
    cp /usr/lib/libunwind.a "${RUST_TOOLCHAIN}/lib/rustlib/${RUST_TARGET}/lib/self-contained/libunwind.a" && \
    rm -rf /tmp/llvm-runtimes /root/packages /var/cache/distfiles/*

RUN mkdir -p /run/nginx && \
    mkdir -p /var/www/public && \
    chown -R linux_user:linux_user /var/www && \
    mkdir -p /etc/php && \
    touch /etc/php/php.ini && \
    chmod 666 /etc/php/php.ini
COPY nginx.conf /etc/nginx/http.d/default.conf
COPY php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

COPY start.sh /start.sh
RUN chmod +x /start.sh

WORKDIR /project

CMD ["/start.sh"]
