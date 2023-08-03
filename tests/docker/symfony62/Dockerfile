ARG PHP_VERSION
ARG VARIANT
ARG TRACER_VERSION

FROM dd-appsec-php-apache2-fpm:$PHP_VERSION-$VARIANT-tracer$TRACER_VERSION
ARG TRACER_VERSION

ADD tests/docker/symfony62/php.ini /etc/php/
RUN echo "datadog.trace.request_init_hook=/opt/datadog/dd-library/$TRACER_VERSION/dd-trace-sources/bridge/dd_wrap_autoloader.php" >> /etc/php/php.ini
RUN cp /etc/php/php.ini $(php-config --ini-path)

RUN rm -rf /var/www/html
ADD --chown=www-data:www-data examples/symfony62/ /var/www/html/

RUN rm /etc/apache2/sites-available/*
ADD tests/docker/symfony62/php-site.conf /etc/apache2/sites-available/

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

#Symfony 6.2
RUN cd /var/www/html/ && php -d memory_limit=-1 /usr/bin/composer install && \
php bin/console doctrine:database:drop --force && \
php bin/console doctrine:database:create && \
php bin/console doctrine:migrations:migrate -n && \
php bin/console doctrine:fixtures:load -n && \
chown www-data.www-data var/app.db

ADD tests/docker/symfony62/entrypoint.sh /

RUN a2enmod rewrite