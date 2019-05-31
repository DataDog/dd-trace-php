FROM ubuntu:16.04

# Install some helpful tools
RUN apt-get update \
    && apt-get install -y locales wget curl zip unzip vim git \
    && locale-gen en_US.UTF-8

ENV LANG en_US.UTF-8
ENV LANGUAGE en_US:en
ENV LC_ALL en_US.UTF-8

COPY bin/ /usr/bin/

# Install PHP dependencies
RUN php-deps

# Install PHP versions
# The default PHP version will be set to the first one installed
RUN build-php 7.3 \
  && build-php 7.2 \
  && build-php 7.1 \
  && build-php 7.0 \
  && build-php 5.6
# TODO: Make PHP 5.4 build

# Install Composer
RUN php -r "readfile('http://getcomposer.org/installer');" | php -- --install-dir=/usr/bin/ --filename=composer
