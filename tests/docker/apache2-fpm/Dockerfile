ARG PHP_VERSION
ARG VARIANT
FROM dd-appsec-php-extension:$PHP_VERSION-$VARIANT as extension

FROM datadog/dd-appsec-php-ci:php-$PHP_VERSION-$VARIANT as ext-build

RUN apt-get update && apt-get install -y \
	apache2 \
	&& rm -rf /var/lib/apt/lists/*

ARG TRACER_VERSION
#RUN curl -Lf -o /tmp/dd-library-php-setup.php https://raw.githubusercontent.com/DataDog/dd-appsec-php/installer/dd-library-php-setup.php && \
    #PHP_INI_SCAN_DIR=/etc/php/ php /tmp/dd-library-php-setup.php --no-appsec --tracer-version $TRACER_VERSION --php-bin all

RUN curl -Lf -o /tmp/dd-library.tar.gz https://output.circle-artifacts.com/output/job/434c6010-da34-4df5-9ce9-1531bb0e60f8/artifacts/0/datadog-php-tracer-0.90.0+36ed038a4beff76c7a4cdeac002a02d4060eaadb.x86_64.tar.gz && \
    curl -Lf -o /tmp/dd-library-php-setup.php https://raw.githubusercontent.com/DataDog/dd-appsec-php/installer/dd-library-php-setup.php && \
    PHP_INI_SCAN_DIR=/etc/php/ php /tmp/dd-library-php-setup.php --php-bin all --no-appsec --tracer-file /tmp/dd-library.tar.gz

RUN rm -rf /var/www/html
ADD --chown=www-data:www-data examples/webroot/ /var/www/html/

COPY --from=extension /opt/ /opt/
RUN find /opt -name ddappsec.so -exec ln -s -v '{}' $(php -r 'echo ini_get("extension_dir");') \;

RUN touch /tmp/appsec.log && chown www-data.www-data /tmp/appsec.log

COPY --from=dd-appsec-php-helper /opt /opt
ADD tests/docker/php.ini /etc/php/
ADD tests/docker/apache2-fpm/entrypoint.sh /

ARG VARIANT
ADD tests/docker/apache2-fpm/php-site.conf /etc/apache2/sites-available/
ADD tests/docker/fpm-common/php-fpm.conf /etc/
RUN mkdir /etc/php-fpm.d/
ADD tests/docker/fpm-common/www.conf /etc/php-fpm.d/
RUN a2enmod proxy_fcgi
RUN a2dissite 000-default
RUN a2ensite php-site

ENV DD_TRACE_ENABLED=true
ENV DD_TRACE_GENERATE_ROOT_SPAN=true

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]

