FROM ubuntu:18.04 AS base

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y \
        autoconf \
        automake \
        bison \
        build-essential \
        curl \
        gettext \
        git \
        libcurl4-gnutls-dev \
        libssl-dev \
        libtool \
        libxml2-dev \
        libfcgi0ldbl \
        nginx \
        re2c \
        supervisor \
        unzip \
        wget \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Installing PHP
ARG PHP_VERSION
WORKDIR /php-src
ADD https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz php.tar.gz
RUN tar -zxvf php.tar.gz --strip-components=1
RUN rm php.tar.gz
RUN ./configure \
    --prefix=/opt/php \
    --enable-fpm \
    --with-fpm-user=www-data \
    --with-fpm-group=www-data \
    --enable-mbstring \
    --enable-opcache \
    --with-curl \
    --with-gettext \
    --with-pear \
    --with-openssl \
    --without-pdo-sqlite \
    --without-sqlite3
RUN make -j2
RUN make install
ENV PATH="/opt/php/bin:/opt/php/sbin:${PATH}"

# Installing composer
WORKDIR /tmp
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
RUN php composer-setup.php --quiet
RUN rm composer-setup.php
RUN mv composer.phar /usr/local/bin/composer

# Setup web app
WORKDIR /var/www/html/
COPY Laravel57 /var/www/html/
RUN composer update --no-dev

EXPOSE 80

RUN touch /var/log/php-fpm.log && chmod 777 /var/log/php-fpm.log

# Copying configuration files
COPY supervisor.conf /etc/supervisor/conf.d/supervisor.conf
COPY nginx.conf /etc/nginx/nginx.conf
COPY php-fpm.conf /opt/php/etc/php-fpm.conf
COPY local-run.sh local-run.sh

# Adding the latest version of dd-doctor.php
ADD https://raw.githubusercontent.com/DataDog/dd-trace-php/master/src/dd-doctor.php /var/www/html/public/dd-doctor.php
RUN chmod a+r /var/www/html/public/dd-doctor.php

CMD [ "supervisord" ]
