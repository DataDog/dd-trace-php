FROM centos:6 as base

RUN yum update -y \
    && yum install -y \
        centos-release-scl \
        curl \
        gcc \
        gcc-c++ \
        git \
        libcurl-devel \
        libxml2-devel \
        make \
        openssl-devel \
        pkg-config \
        postgresql-devel \
        readline-devel \
        scl-utils \
        unzip \
        vim \
        zlib-devel \
    && yum install -y devtoolset-7 \
    && yum clean all \
    && cd /tmp \
    && curl -OL https://www.php.net/distributions/php-5.5.38.tar.bz2 \
    && (echo "473c81ebb2e48ca468caee031762266651843d7227c18a824add9b07b9393e38  php-5.5.38.tar.bz2" | sha256sum -) \
    && mkdir -p /usr/local/src/php \
    && cd /usr/local/src/php \
    && tar -xf /tmp/php-5.5.38.tar.bz2 \
    && mv php-5.5.38 5.5

FROM base as build
ADD configure.sh /root/configure.sh

FROM build as php55-ndebug-nts
RUN mkdir -p /tmp/build/php-5.5 \
    && cd /tmp/build/php-5.5 \
    && $SHELL /root/configure.sh \
        --prefix=/opt/php/5.5 \
        --with-config-file-path=/opt/php/5.5/etc \
        --with-config-file-scan-dir=/opt/php/5.5/etc/conf.d \
    && make all install \
    && mkdir -p /opt/php/5.5/etc/conf.d \
    && sed 's/;date.timezone =/date.timezone = UTC/' </usr/local/src/php/5.5/php.ini-development >/opt/php/5.5/etc/php.ini

FROM build as php55-debug-nts
RUN mkdir -p /tmp/build/php-5.5-debug \
    && cd /tmp/build/php-5.5-debug \
    && $SHELL /root/configure.sh \
        --enable-debug \
        --prefix=/opt/php/5.5-debug \
        --with-config-file-path=/opt/php/5.5-debug/etc \
        --with-config-file-scan-dir=/opt/php/5.5-debug/etc/conf.d \
    && make all install \
    && mkdir -p /opt/php/5.5-debug/etc/conf.d \
    && sed 's/;date.timezone =/date.timezone = UTC/' </usr/local/src/php/5.5/php.ini-development >/opt/php/5.5-debug/etc/php.ini

FROM build as php55-ndebug-zts
RUN mkdir -p /tmp/build/php-5.5-zts \
    && cd /tmp/build/php-5.5-zts \
    && $SHELL /root/configure.sh \
        --enable-maintainer-zts \
        --prefix=/opt/php/5.5-zts \
        --with-config-file-path=/opt/php/5.5-zts/etc \
        --with-config-file-scan-dir=/opt/php/5.5-zts/etc/conf.d \
    && make all install \
    && mkdir -p /opt/php/5.5-zts/etc/conf.d \
    && sed 's/;date.timezone =/date.timezone = UTC/' </usr/local/src/php/5.5/php.ini-development >/opt/php/5.5-zts/etc/php.ini

FROM base as ultimate
COPY --from=php55-ndebug-nts /opt/php/5.5           /opt/php/5.5
COPY --from=php55-debug-nts  /opt/php/5.5-debug     /opt/php/5.5-debug
COPY --from=php55-ndebug-zts  /opt/php/5.5-zts /opt/php/5.5-zts

RUN printf "source scl_source enable devtoolset-7" | tee -a /etc/profile.d/zzz-ddtrace.sh /etc/bashrc
ENV BASH_ENV="/etc/profile.d/zzz-ddtrace.sh"

COPY switch-php /usr/local/bin/
RUN set -eux; \
# Set the default PHP version
    switch-php 5.5-debug;
