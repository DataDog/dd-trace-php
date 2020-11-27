FROM ubuntu:18.04

ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive

# Install common libraries and tools
ADD dockerfiles/ubuntu/install-common.sh /scripts/install-common.sh
RUN /scripts/install-common.sh

# Install PHP
ADD dockerfiles/ubuntu/install-php-and-composer.sh /scripts/install-php-and-composer.sh
RUN /scripts/install-php-and-composer.sh

# RUN touch /var/log/php-fpm.log && chmod 777 /var/log/php-fpm.log

# # Setup web app
# ARG APP_PATH
# WORKDIR /var/www/html/
# ADD ./$APP_PATH /var/www/html/
# RUN composer update --no-dev
# RUN chmod -R a+w /var/www/html/storage

RUN mkdir -p /var/www/html/public
RUN echo "<?php echo 'hi from php'; ?>" > /var/www/html/public/index.php

# # Adding configuration files
ADD dockerfiles/supervisor.conf /etc/supervisor/conf.d/supervisor.conf
# ADD dockerfiles/ubuntu/nginx.conf /etc/nginx/nginx.conf
ADD dockerfiles/php-fpm.conf /etc/php/${PHP_VERSION}/fpm/php-fpm.conf
# ADD dockerfiles/ubuntu/opcache.ini /etc/php/${PHP_VERSION}/fpm/conf.d/10-opcache.ini
# ADD dockerfiles/ubuntu/logging.ini /etc/php/${PHP_VERSION}/fpm/80-logging.ini

# ADD dockerfiles/ubuntu/local-run.sh /scripts/local-run.sh

EXPOSE 9000
CMD [ "php-fpm", "-R", "-F" ]
