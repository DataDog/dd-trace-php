ARG PHP_VERSION
ARG VARIANT
FROM datadog/dd-appsec-php-ci:php-$PHP_VERSION-$VARIANT

RUN apt-get update && apt-get install -y \
    apache2 \
    && rm -rf /var/lib/apt/lists/*

RUN usermod -a -G linux_users www-data

RUN rm -rf /var/www/html
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
RUN a2enmod rewrite

ADD apache2-fpm/entrypoint.sh /

ADD apache2-fpm/php-site.conf /etc/apache2/sites-available/
ADD fpm-common/php-fpm.conf /etc/
RUN mkdir /etc/php-fpm.d/
ADD fpm-common/www.conf /etc/php-fpm.d/
RUN a2enmod proxy_fcgi
RUN a2dissite 000-default
RUN a2ensite php-site

RUN chmod a+rx /root

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]

