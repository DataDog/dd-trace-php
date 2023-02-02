ARG PHP_VERSION
ARG VARIANT
ARG ALPINE_VERSION
FROM dd-appsec-php-extension:$PHP_VERSION-$VARIANT as extension

FROM php:$PHP_VERSION-fpm-alpine$ALPINE_VERSION

RUN apk add --no-cache apache2 apache2-proxy libexecinfo bash

RUN curl -Lf -o datadog-php-tracer-1.0.0-nightly.x86_64.tar.gz https://output.circle-artifacts.com/output/job/524b8724-5d23-47d9-ba2c-c134bd4a0ed1/artifacts/0/datadog-php-tracer-1.0.0-nightly.x86_64.tar.gz
RUN curl -Lf -o /tmp/dd-library-php-setup.php https://raw.githubusercontent.com/DataDog/dd-appsec-php/installer/dd-library-php-setup.php && \
    PHP_INI_SCAN_DIR=/etc/php/ php /tmp/dd-library-php-setup.php --no-appsec --tracer-file datadog-php-tracer-1.0.0-nightly.x86_64.tar.gz --php-bin all

#ARG TRACER_VERSION
#RUN curl -Lf -o /tmp/dd-library-php-setup.php https://raw.githubusercontent.com/DataDog/dd-appsec-php/installer/dd-library-php-setup.php && \
    #PHP_INI_SCAN_DIR=/etc/php/ php /tmp/dd-library-php-setup.php --no-appsec --tracer-version $TRACER_VERSION --php-bin all

RUN rm -rf /var/www/localhost/htdocs
ADD --chown=www-data:www-data examples/webroot/ /var/www/localhost/htdocs/

COPY --from=extension /opt/ /opt/
RUN find /opt -name ddappsec.so -exec ln -s -v '{}' $(php -r 'echo ini_get("extension_dir");') \;

COPY --from=dd-appsec-php-helper /opt /opt
ADD tests/docker/php.ini /etc/php/
ADD tests/docker/alpine-apache2-fpm/entrypoint.sh /

ADD tests/docker/alpine-apache2-fpm/php-site.conf /etc/apache2/conf.d/

RUN mkdir /etc/php-fpm.d/
ADD tests/docker/fpm-common/php-fpm.conf /etc/
ADD tests/docker/fpm-common/www.conf /etc/php-fpm.d/

RUN mkdir -p /var/log/apache2 /run/apache2

ENV DD_TRACE_ENABLED=true
ENV DD_TRACE_GENERATE_ROOT_SPAN=true

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]

