ARG PHP_VERSION
ARG VARIANT
FROM dd-appsec-php-extension:$PHP_VERSION-$VARIANT as extension

FROM datadog/dd-appsec-php-ci:php-$PHP_VERSION-$VARIANT as ext-build

RUN apt-get update && apt-get install -y \
	apache2 \
	&& rm -rf /var/lib/apt/lists/*

ARG TRACER_VERSION
RUN curl -Lf -o /tmp/dd-library-php-setup.php https://raw.githubusercontent.com/DataDog/dd-trace-php/cataphract/appsec-installer/dd-library-php-setup.php && \
    curl -L -o /tmp/datadog-php-tracer.tar.gz  https://output.circle-artifacts.com/output/job/f2c4f822-8802-4972-a747-66819d2065e0/artifacts/0/datadog-php-tracer-1.0.0-nightly.x86_64.tar.gz && \
	PHP_INI_SCAN_DIR=/etc/php/ php /tmp/dd-library-php-setup.php --no-appsec --tracer-file /tmp/datadog-php-tracer.tar.gz --php-bin all
#RUN curl -Lf -o /tmp/ddtrace.tar.gz https://github.com/DataDog/dd-trace-php/archive/$TRACER_VERSION.tar.gz && \
#	mkdir /tmp/ddtrace-build/ && cd /tmp/ddtrace-build && \
#	tar --strip-components=1 -xzf /tmp/ddtrace.tar.gz && \
#	phpize && CFLAGS="-O -g" ./configure && make -j install

RUN rm -rf /var/www/html
ADD --chown=www-data:www-data examples/webroot/ /var/www/html/

COPY --from=extension /opt/ /opt/
RUN find /opt -name ddappsec.so -exec ln -s -v '{}' $(php -r 'echo ini_get("extension_dir");') \;

COPY --from=dd-appsec-php-helper /opt /opt
ADD tests/docker/php.ini /etc/php/
ADD tests/docker/apache2-mod/entrypoint.sh /

ARG PHP_VERSION
ARG VARIANT
ADD tests/docker/apache2-mod/php.conf /etc/apache2/mods-available/
ADD tests/docker/apache2-mod/php.load /etc/apache2/mods-available/
RUN /bin/bash -c 'if [[ "${PHP_VERSION:0:1}" -ge 8 ]]; then sed -i "s/%PHP_MAJOR_VERSION//g" /etc/apache2/mods-available/php.{conf,load}; else \
  sed -i "s/%PHP_MAJOR_VERSION/${PHP_VERSION:0:1}/g" /etc/apache2/mods-available/php.{conf,load}; fi'
RUN if echo $VARIANT | grep -q zts; \
	then sed -i "s/%MPM/event/" /etc/apache2/mods-available/php.load; \
	else sed -i "s/%MPM/prefork/" /etc/apache2/mods-available/php.load; \
	fi
RUN if ! { echo $VARIANT | grep -q zts; }; then a2dismod mpm_event; a2enmod mpm_prefork; fi
RUN a2enmod php

ENV DD_TRACE_ENABLED=true
ENV DD_TRACE_GENERATE_ROOT_SPAN=true

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]

