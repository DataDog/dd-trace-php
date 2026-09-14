FROM datadog/dd-appsec-php-ci:php-deps

ADD build_dev_php.sh /build/php/
ADD php_patches/ /build/php/php_patches/
RUN mkdir -p /etc/php && ln -s /root/php/php.ini /etc/php/
ARG PHP_VERSION
ARG VARIANT

ENV NO_APX_WRAPPER=1

# PHP 8.4 checks if /usr/sbin/apache2 exists
RUN touch /usr/sbin/apache2
RUN chmod +x /usr/sbin/apache2
RUN USER=root /build/php/build_dev_php.sh "$PHP_VERSION" "$VARIANT"
RUN rm /usr/sbin/apache2

ADD php.ini /root/php/php.ini
ENV PHPRC=/root/php/php.ini

RUN chmod +x /root

ENV PATH=/root/php/$PHP_VERSION-$VARIANT/bin/:/root/php/$PHP_VERSION-$VARIANT/sbin/:$PATH
ENV LD_LIBRARY_PATH=/root/php/icu-60/lib

RUN groupadd -g 1000 linux_users && \
    useradd -G linux_users -u 1000 linux_user

ENTRYPOINT ["php"]
