FROM php:7.3-apache

ARG DD_TRACER_VERSION

# Begin of tracer installation
RUN curl -L -o dd-trace.deb https://github.com/DataDog/dd-trace-php/releases/download/${DD_TRACER_VERSION}/datadog-php-tracer_${DD_TRACER_VERSION}_amd64.deb \
    && dpkg -i dd-trace.deb \
    && rm dd-trace.deb
# End of tracer installation

ADD virtual-host.conf /etc/apache2/sites-available/000-default.conf

ADD public/ /var/www/html/
