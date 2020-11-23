FROM ubuntu:18.04

ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive

# Install common libraries and tools
ADD docker-scripts/install-common.sh /scripts/install-common.sh
RUN /scripts/install-common.sh

# Install PHP
ADD docker-scripts/install-php-and-composer.sh /scripts/install-php-and-composer.sh
RUN /scripts/install-php-and-composer.sh

RUN touch /var/log/php-fpm.log && chmod 777 /var/log/php-fpm.log

# Setup web app
ARG APP_PATH
WORKDIR /var/www/html/
ADD ./$APP_PATH /var/www/html/
RUN composer update --no-dev
RUN chmod -R a+w /var/www/html/storage

# Adding configuration files
ADD docker-scripts/supervisor.conf /etc/supervisor/conf.d/supervisor.conf
ADD docker-scripts/nginx.conf /etc/nginx/nginx.conf
ADD docker-scripts/php-fpm.conf /etc/php/${PHP_VERSION}/fpm/php-fpm.conf
ADD docker-scripts/opcache.ini /etc/php/${PHP_VERSION}/fpm/conf.d/10-opcache.ini
ADD docker-scripts/logging.ini /etc/php/${PHP_VERSION}/fpm/80-logging.ini

ADD docker-scripts/local-run.sh /scripts/local-run.sh

EXPOSE 80
CMD [ "supervisord" ]
