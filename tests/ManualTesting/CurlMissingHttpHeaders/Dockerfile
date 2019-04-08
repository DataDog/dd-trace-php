FROM ubuntu:16.04

RUN apt update \
    && apt install -y \
        curl \
        wget \
        software-properties-common \
        python-software-properties \
    && rm -rf /var/lib/apt/lists/*

RUN LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php \
    && apt update \
    && DEBIAN_FRONTEND=noninteractive apt install -y \
        php7.2 \
        php7.2-cli \
        php7.2-common \
        php7.2-curl \
    && rm -rf /var/lib/apt/lists/*

ARG DD_TRACE_VERSION=0.17.0
RUN wget https://github.com/DataDog/dd-trace-php/releases/download/${DD_TRACE_VERSION}/datadog-php-tracer_${DD_TRACE_VERSION}_amd64.deb \
    && dpkg -i datadog-php-tracer_${DD_TRACE_VERSION}_amd64.deb \
    && rm datadog-php-tracer_${DD_TRACE_VERSION}_amd64.deb

CMD bash
