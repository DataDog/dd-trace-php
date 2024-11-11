ARG PHP_VERSION
ARG VARIANT
FROM datadog/dd-appsec-php-ci:php-$PHP_VERSION-$VARIANT

RUN apt-get update && apt-get install -y \
    apache2 \
    && rm -rf /var/lib/apt/lists/*

RUN usermod -a -G linux_users www-data

RUN rm -rf /var/www/html
RUN sed -i 's@/var/www/html@/var/www/public@' /etc/apache2/sites-available/000-default.conf
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
RUN a2enmod rewrite

ADD apache2-mod/entrypoint.sh /

ARG PHP_VERSION
ARG VARIANT
ADD apache2-mod/php.conf /etc/apache2/mods-available/
ADD apache2-mod/php.load /etc/apache2/mods-available/
RUN /bin/bash -c 'if [[ "${PHP_VERSION:0:1}" -ge 8 ]]; then sed -i "s/%PHP_MAJOR_VERSION//g" /etc/apache2/mods-available/php.{conf,load}; else \
  sed -i "s/%PHP_MAJOR_VERSION/${PHP_VERSION:0:1}/g" /etc/apache2/mods-available/php.{conf,load}; fi'
RUN if echo $VARIANT | grep -q zts; \
	then sed -i "s/%MPM/event/" /etc/apache2/mods-available/php.load; \
	else sed -i "s/%MPM/prefork/" /etc/apache2/mods-available/php.load; \
	fi
RUN if ! { echo $VARIANT | grep -q zts; }; then a2dismod mpm_event; a2enmod mpm_prefork; fi
RUN a2enmod php

RUN chmod a+rx /root

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]

