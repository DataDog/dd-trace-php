#!/usr/bin/env bash

apt-get install -y \
        libcurl4-gnutls-dev \
        libmemcached-dev \
        valgrind \
        vim \
        default-mysql-client \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl install memcached \
    && pecl install apcu-5.1.18 \
    && docker-php-ext-enable apcu \
    && docker-php-ext-enable memcached \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && docker-php-source delete
