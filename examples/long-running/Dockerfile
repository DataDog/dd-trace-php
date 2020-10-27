FROM php:7.3-cli

ARG DD_TRACER_VERSION=0.48.3
RUN curl -o /tmp/dd-trace-php.deb -L https://github.com/DataDog/dd-trace-php/releases/download/${DD_TRACER_VERSION}/datadog-php-tracer_${DD_TRACER_VERSION}_amd64.deb
RUN dpkg -i /tmp/dd-trace-php.deb

WORKDIR /app

CMD DD_TRACE_CLI_ENABLED=true \
    DD_TRACE_AUTO_FLUSH_ENABLED=true \
    DD_TRACE_GENERATE_ROOT_SPAN=false \
    php long-running-script.php
