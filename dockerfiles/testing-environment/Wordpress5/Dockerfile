FROM ubuntu:18.04

ENV DEBIAN_FRONTEND=noninteractive

# Install common tools
ADD docker-scripts/install-common.sh /scripts/install-common.sh
RUN /scripts/install-common.sh

# Install PHP 7.3
ADD docker-scripts/install-php.sh /scripts/install-php.sh
RUN /scripts/install-php.sh

ADD app /var/www/html/public

# php-fpm configuration
ADD docker-scripts/php-fpm.conf /etc/php/7.3/fpm/php-fpm.conf
ADD docker-scripts/opcache.ini /etc/php/7.3/fpm/conf.d/10-opcache.ini
ADD docker-scripts/php-relenv.ini /etc/php/7.3/fpm/conf.d/php-relenv.ini

ADD docker-scripts/nginx.conf /etc/nginx/nginx.conf
ADD docker-scripts/supervisord.conf /etc/supervisor/supervisord.conf
ADD docker-scripts/init-db.sh /scripts/init-db.sh
ADD docker-scripts/db-data.sql /scripts/db-data.sql
ADD docker-scripts/local-run.sh /scripts/local-run.sh

EXPOSE 80
CMD bash
