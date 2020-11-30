FROM ubuntu:18.04

ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive

# Install common libraries and tools
ADD files/install-common.sh /scripts/install-common.sh
RUN /scripts/install-common.sh

# Install PHP
ADD files/install-php-and-composer.sh /scripts/install-php-and-composer.sh
RUN /scripts/install-php-and-composer.sh

RUN mkdir -p /var/www/html/public

# # Adding configuration files
ADD files/supervisor.conf /etc/supervisor/conf.d/supervisor.conf
ADD files/nginx.conf /etc/nginx/nginx.conf
ADD files/php-fpm.conf /etc/php/${PHP_VERSION}/fpm/php-fpm.conf
ADD files/opcache.ini /etc/php/${PHP_VERSION}/fpm/conf.d/10-opcache.ini

ADD files/local-run.sh /scripts/local-run.sh

ADD ./app /var/www/html
WORKDIR /var/www/html/
ENV COMPOSER composer-${PHP_VERSION}.json
RUN if [ -f "composer-*.json" ]; then composer update; fi

EXPOSE 80
CMD [ "supervisord" ]
