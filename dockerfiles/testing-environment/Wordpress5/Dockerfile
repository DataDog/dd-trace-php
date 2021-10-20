FROM ubuntu:18.04

ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive

# Install common tools
ADD dockerscripts/install-common.sh /scripts/install-common.sh
RUN /scripts/install-common.sh

# Install PHP
ADD dockerscripts/install-php.sh /scripts/install-php.sh
RUN /scripts/install-php.sh

ADD app /var/www/html/public

# php-fpm configuration
ADD dockerscripts/php-fpm.conf /etc/php/${PHP_VERSION}/fpm/php-fpm.conf
ADD dockerscripts/opcache.ini /etc/php/${PHP_VERSION}/fpm/conf.d/10-opcache.ini
ADD dockerscripts/php-relenv.ini /etc/php/${PHP_VERSION}/fpm/conf.d/php-relenv.ini

ADD dockerscripts/nginx.conf /etc/nginx/nginx.conf
ADD dockerscripts/supervisord.conf /etc/supervisor/supervisord.conf
ADD dockerscripts/init-db.sh /scripts/init-db.sh
ADD dockerscripts/db-data.sql /scripts/db-data.sql
ADD dockerscripts/local-run.sh /scripts/local-run.sh

EXPOSE 80
CMD bash
