FROM centos:7

ARG PHP_MAJOR
ARG PHP_MINOR

RUN \
    rpm -Uvh https://dl.fedoraproject.org/pub/epel/epel-release-latest-7.noarch.rpm \
    && yum install -y wget nginx httpd unzip \
    && rpm -Uvh http://rpms.famillecollet.com/enterprise/remi-release-7.rpm \
    && yum --enablerepo=remi-php${PHP_MAJOR}${PHP_MINOR} install -y \
            php-cli \
            php-curl \
            php-fpm \
            php-memcached \
            php-opcache \
            php-pdo_mysql \
            php-pear \
            php-pecl-redis \
            mod_php

# Installing composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Preparing PHP-FPM
RUN mkdir -p /run/php-fpm
RUN sed -i 's/^listen = .*$/listen = 9000/g' /etc/php-fpm.d/www.conf

# Preparing NGINX
RUN groupadd www-data
RUN adduser -M --system -g www-data www-data
ADD nginx.conf /etc/nginx/conf.d/default.conf

ADD run.sh /scripts/run.sh

CMD [ "bash", "/scripts/run.sh" ]
