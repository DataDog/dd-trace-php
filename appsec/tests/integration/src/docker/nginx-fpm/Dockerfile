ARG PHP_VERSION
ARG VARIANT

FROM datadog/dd-appsec-php-ci:php-$PHP_VERSION-$VARIANT

RUN apt-get update && apt-get install -y \
    nginx \
    && rm -rf /var/lib/apt/lists/*

RUN usermod -a -G linux_users www-data

RUN rm -rf /var/www/html

ADD nginx-fpm/entrypoint.sh /
ADD nginx-fpm/default /etc/nginx/sites-available/default

ADD fpm-common/php-fpm.conf /etc/
RUN mkdir /etc/php-fpm.d/
ADD fpm-common/www.conf /etc/php-fpm.d/

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]

