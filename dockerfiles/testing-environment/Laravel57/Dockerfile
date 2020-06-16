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
        vim \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Installing PHP
ARG PHP_VERSION
WORKDIR /php-src
ADD https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz php.tar.gz
RUN tar -zxvf php.tar.gz --strip-components=1
RUN rm php.tar.gz
RUN ./configure \
    CFLAGS="-fstack-protector-strong -fpic -fpie -O2 -D_LARGEFILE_SOURCE -D_FILE_OFFSET_BITS=64" \
    --prefix=/opt/php \
    --with-config-file-scan-dir=/opt/php/lib/conf.d \
    --enable-fpm \
    --with-fpm-user=www-data \
    --with-fpm-group=www-data \
    --enable-mbstring \
    --enable-opcache \
    --with-curl \
    --with-gettext \
    --with-mysqli \
    --with-pdo-mysql \
    --with-pear \
    --with-openssl \
    --without-pdo-sqlite \
    --without-sqlite3
RUN make -j$(nproc)
RUN make install
ENV PATH="/opt/php/bin:/opt/php/sbin:${PATH}"

# Installing composer
WORKDIR /tmp
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
RUN php composer-setup.php --quiet
RUN rm composer-setup.php
RUN mv composer.phar /usr/local/bin/composer

EXPOSE 80

RUN mkdir -p /opt/php/lib/conf.d/

# Adding xdebug
RUN pecl install xdebug
# RUN echo "zend_extension=/opt/php/lib/php/extensions/no-debug-non-zts-20180731/xdebug.so" > /opt/php/lib/conf.d/20-xdebug.ini

RUN touch /var/log/php-fpm.log && chmod 777 /var/log/php-fpm.log

# Setup web app
WORKDIR /var/www/html/
COPY ./app /var/www/html/
RUN composer update --no-dev

# Copying configuration files
COPY supervisor.conf /etc/supervisor/conf.d/supervisor.conf
COPY nginx.conf /etc/nginx/nginx.conf
COPY php-fpm.conf /opt/php/etc/php-fpm.conf
COPY local-run.sh /local-run.sh
COPY opcache.ini /opt/php/lib/conf.d/10-opcache.ini
COPY logging.ini /opt/php/lib/conf.d/80-logging.ini

# Adding the latest version of dd-doctor.php
ADD https://raw.githubusercontent.com/DataDog/dd-trace-php/master/src/dd-doctor.php /var/www/html/public/dd-doctor.php
RUN chmod a+r /var/www/html/public/dd-doctor.php

CMD [ "supervisord" ]
