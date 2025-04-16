FROM dunglas/frankenphp:1.4-php8.4

ENV PHP_VERSION=8.4
ENV VARIANT=zts

RUN pecl install xdebug-3.4.2

RUN apt-get update && apt-get install -y procps libcurl4-gnutls-dev git unzip
ADD Caddyfile /etc/caddy/Caddyfile
RUN mkdir -p /etc/php && ln -s /etc/php/php.ini /usr/local/etc/php

ADD docker-php-entrypoint /usr/local/bin/
ADD entrypoint_classic.sh /usr/local/bin/

HEALTHCHECK NONE
EXPOSE 80