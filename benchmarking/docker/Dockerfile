FROM ubuntu:16.04

# Install some helpful tools
RUN apt-get update \
    && apt-get install -y locales wget curl zip unzip vim git \
    && locale-gen en_US.UTF-8

ENV LANG en_US.UTF-8
ENV LANGUAGE en_US:en
ENV LC_ALL en_US.UTF-8

# Install PHP dependencies
RUN ./bin/php-deps

# Install PHP versions
RUN ./bin/build-php 7.3 \
  && ./bin/build-php 7.2 \
  && ./bin/build-php 7.1 \
  && ./bin/build-php 7.0 \
  && ./bin/build-php 5.6
# TODO: Make PHP 5.4 build

# Set default PHP version
RUN ./bin/switch-php 7.3

# Install Composer
RUN php -r "readfile('http://getcomposer.org/installer');" | php -- --install-dir=/usr/bin/ --filename=composer
