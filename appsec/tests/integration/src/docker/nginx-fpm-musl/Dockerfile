FROM php:8.5-fpm-alpine

# doubles as php/toolchain image for musl

RUN apk add --no-cache \
    autoconf bash bison clang clang-dev cmake curl curl-dev \
    g++ gcc gdb git libc-dev linux-headers llvm-libunwind-static make \
    musl-dev nginx oniguruma-dev openssl-dev patchelf pkgconf re2c \
    libxml2-dev libzip-dev xz-static zlib-dev \
    vim


RUN adduser -D -u 1000 linux_user

ENV CARGO_HOME=/usr/local/cargo \
    RUSTUP_HOME=/usr/local/rustup \
    PATH="/usr/local/cargo/bin:${PATH}" \
    LIBCLANG_PATH=/usr/lib

RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | \
    sh -s -- -y --default-toolchain 1.84.1 --no-modify-path && \
    chmod -R 777 /usr/local/cargo /usr/local/rustup && \
    ln -sf /usr/local/cargo/bin/{cargo,rustc} /usr/local/bin/ && \
    rustup toolchain install nightly-$(uname -m)-unknown-linux-musl && \
    rustup component add rust-src --toolchain nightly-$(uname -m)-unknown-linux-musl

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
