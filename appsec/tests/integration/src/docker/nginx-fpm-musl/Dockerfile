FROM php:8.5-fpm-alpine

# doubles as php/toolchain image for musl

RUN apk add --no-cache \
    autoconf automake bash bison cmake curl curl-dev \
    build-base g++ gcc gdb git libc-dev linux-headers llvm22 make \
    musl-dev nginx oniguruma-dev openssl-dev patch patchelf pkgconf re2c libtool \
    libxml2-dev libzip-dev python3 xz zlib-dev


RUN adduser -D -u 1000 linux_user

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
