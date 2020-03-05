FROM ubuntu:18.04 AS base

RUN apt-get update \
    && apt-get install -y \
        autoconf \
        automake \
        bison \
        build-essential \
        curl \
        gettext \
        git \
        libcurl4-gnutls-dev \
        libssl-dev \
        libtool \
        libxml2-dev \
        nginx \
        re2c \
        supervisor \
        unzip \
        wget \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Building PHP
ARG PHP_VERSION
RUN git clone https://github.com/php/php-src.git --depth=1 --branch "PHP-${PHP_VERSION}" --single-branch /php-src
WORKDIR /php-src
RUN ./buildconf
RUN ./configure \
    --enable-fpm \
    --enable-mbstring \
    --enable-opcache \
    --with-curl \
    --with-openssl \
    --without-pdo-sqlite \
    --without-sqlite3
RUN make -j2
RUN make install

# Installing composer
WORKDIR /tmp
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
RUN php composer-setup.php --quiet
RUN rm composer-setup.php
RUN mv composer.phar /usr/local/bin/composer

# Setup web app
WORKDIR /var/www/html/
COPY Laravel57 /var/www/html/
RUN composer update --no-dev

# Configuring nginx and PHP
RUN ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && ln -sf /dev/stderr /var/log/php-fpm.log
COPY nginx.conf /etc/nginx/nginx.conf
COPY php-fpm.conf /usr/local/etc/php-fpm.conf

# Configuring supervisord
COPY supervisor.conf /etc/supervisor/conf.d/supervisor.conf

# Installing tracer if provided
ARG TRACER_DOWNLOAD_URL
RUN if [ ! -z "${TRACER_DOWNLOAD_URL}" ] ; then \
        wget -O dd-trace-php.deb "${TRACER_DOWNLOAD_URL}"; \
        dpkg -i dd-trace-php.deb; \
    fi

EXPOSE 80
CMD [ "/usr/bin/supervisord" ]
