FROM ubuntu:18.04

RUN apt-get update \
    && apt-get install -y \
        build-essential \
        autoconf \
        automake \
        curl \
        git \
        libtool \
        libxml2-dev \
        libcurl4-gnutls-dev \
        bison \
        re2c \
        wget \
    && rm -rf /var/lib/apt/lists/*

ARG PHP_VERSION

RUN git clone https://github.com/php/php-src.git --depth=1 --branch "PHP-${PHP_VERSION}" --single-branch /php-src

WORKDIR /php-src

CMD bash

RUN ./buildconf
RUN ./configure --with-curl --enable-fpm --without-sqlite3 --without-pdo-sqlite --enable-opcache
RUN make -j2
RUN make install

