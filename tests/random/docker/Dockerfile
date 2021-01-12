FROM centos:7

ARG PHP_MAJOR
ARG PHP_MINOR

RUN \
    rpm -Uvh https://dl.fedoraproject.org/pub/epel/epel-release-latest-7.noarch.rpm \
    && yum install -y wget nginx httpd \
    && rpm -Uvh http://rpms.famillecollet.com/enterprise/remi-release-7.rpm \
    && yum --enablerepo=remi-php${PHP_MAJOR}${PHP_MINOR} install -y \
            php-cli \
            php-fpm \
            php-opcache \
            php-pear \
            mod_php

# Preparing PHP-FPM
RUN mkdir -p /run/php-fpm
RUN sed -i 's/^listen = .*$/listen = 9000/g' /etc/php-fpm.d/www.conf

# Preparing NGINX
RUN groupadd www-data
RUN adduser -M --system -g www-data www-data
# Note: ignoring alias cp='cp -i' via leading `\` to avoid interactive mode for override operation.
# RUN \cp $(pwd)/dockerfiles/verify_packages/nginx.conf /etc/nginx/nginx.conf

CMD bash