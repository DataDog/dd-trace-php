FROM phalconphp/ubuntu-16.04:php-7.2

ARG DD_TRACE_LIBRARY_VERSION

# Install DDTrace deb
ADD https://github.com/DataDog/dd-trace-php/releases/download/${DD_TRACE_LIBRARY_VERSION}/datadog-php-tracer_${DD_TRACE_LIBRARY_VERSION}_amd64.deb datadog-php-tracer.deb
RUN dpkg -i datadog-php-tracer.deb
