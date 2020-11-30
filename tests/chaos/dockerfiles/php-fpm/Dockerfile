ARG php_version

FROM php:${php_version}-fpm

RUN apt-get update \
    && apt-get install -y \
        tcpdump \
        wget \
        gnupg2 \
        procps \
        vim \
        libmemcached-dev \
        zlib1g-dev \
        unzip \
    && apt-get clean

RUN docker-php-ext-install mysqli pdo pdo_mysql

# Opcache
RUN docker-php-ext-install opcache
ADD opcache.ini /usr/local/etc/php/conf.d/opcache.ini

RUN pecl install redis
# RUN pecl install redis-3.1.6

RUN pecl install memcached
RUN docker-php-ext-enable memcached

ADD redis.ini /usr/local/etc/php/conf.d/redis.ini

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

ADD www.conf /usr/local/etc/php-fpm.d/www.conf

RUN mkdir -p /coredumps
RUN chown -R www-data:www-data /coredumps

ADD entrypoint.sh /entrypoint.sh

CMD /entrypoint.sh
