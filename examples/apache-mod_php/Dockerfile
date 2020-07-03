FROM php:7.3-apache

ARG DD_TRACER_VERSION

ADD https://github.com/DataDog/dd-trace-php/releases/download/${DD_TRACER_VERSION}/datadog-php-tracer_${DD_TRACER_VERSION}_amd64.deb /tmp/dd-trace.deb
RUN dpkg -i /tmp/dd-trace.deb

ADD public/ /var/www/html/
