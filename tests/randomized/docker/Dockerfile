FROM centos:7

ARG PHP_MAJOR_MINOR

# Getting the latest nginx
RUN echo $'[nginx]\nname=nginx repo\nbaseurl=https://nginx.org/packages/mainline/centos/7/$basearch/\ngpgcheck=0\nenabled=1' >> /etc/yum.repos.d/nginx.repo

RUN \
    rpm -Uvh https://dl.fedoraproject.org/pub/epel/epel-release-latest-7.noarch.rpm \
    && yum install -y \
        autoconf \
        bzip2-devel \
        elinks \
        gcc \
        gdb \
        git \
        httpd-devel \
        libcurl-devel \
        libmemcached-devel \
        libsodium-devel \
        libsqlite3x-devel \
        libxml2-devel \
        libxslt-devel \
        nc \
        nginx \
        openssl-devel \
        readline-devel \
        unzip \
        vim \
        wget \
    && debuginfo-install -y httpd \
    && yum clean all \
    && rm -rf /var/cache/yum

ADD envs /envs

# Downloading / Extracting PHP
RUN set -eux; \
    source /envs/${PHP_MAJOR_MINOR}.env; \
    curl -L --output php.tar.gz ${PHP_DOWNLOAD_URL}; \
    tar -xvf php.tar.gz -C /usr/local/src/; \
    mv /usr/local/src/php-* /usr/local/src/php; \
    rm php.tar.gz

WORKDIR /usr/local/src/php

RUN set -eux; \
    mkdir -p /etc/php.d; \
    ./configure \
        --prefix=/usr/local \
        --with-config-file-path=/etc \
        --with-config-file-scan-dir=/etc/php.d \
        --enable-calendar \
        --enable-exif \
        --enable-fpm \
        --enable-ftp \
        --enable-mysqlnd \
        --enable-pcntl \
        --enable-shmop \
        --enable-sockets \
        --enable-sysvmsg \
        --enable-sysvsem \
        --enable-sysvshm \
        --with-apxs2 \
        --with-bz2 \
        --with-curl \
        --with-fpm-group=www-data \
        --with-fpm-user=www-data \
        --with-gettext \
        --with-mysqli \
        --with-openssl \
        --with-pdo-mysql \
        --with-pear \
        --with-readline \
        --with-sodium \
        --with-xsl \
        --with-zlib \
    ; \
    make -j "$((`nproc`+1))"; \
    make install

# Enabling Opcache, which is disabled by default
RUN echo "zend_extension=opcache.so" > /etc/php.d/opache.ini

# Igbinary
RUN set -eux; \
    source /envs/${PHP_MAJOR_MINOR}.env; \
    if [[ -z "${IGBINARY_VERSION:-}" ]]; then PECL_SUFFIX=""; else PECL_SUFFIX="-${IGBINARY_VERSION}"; fi; \
    pecl install "igbinary${PECL_SUFFIX}"; \
    echo "extension=igbinary.so" > /etc/php.d/igbinary.ini

# Memached
RUN set -eux; \
    source /envs/${PHP_MAJOR_MINOR}.env; \
    if [[ -z "${MEMCACHED_VERSION:-}" ]]; then PECL_SUFFIX=""; else PECL_SUFFIX="-${MEMCACHED_VERSION}"; fi; \
    printf 'yes' | pecl install "memcached${PECL_SUFFIX}"; \
    echo "extension=memcached.so" > /etc/php.d/memcached.ini

# Redis
RUN set -eux; \
    source /envs/${PHP_MAJOR_MINOR}.env; \
    if [[ -z "${REDIS_VERSION:-}" ]]; then PECL_SUFFIX=""; else PECL_SUFFIX="-${REDIS_VERSION}"; fi; \
    printf 'yes' | pecl install "redis${PECL_SUFFIX}"; \
    echo "extension=redis.so" > /etc/php.d/redis.ini

# Create coredumps folder
# If not generated, see: https://fromdual.com/hunting-the-core
RUN mkdir -p /tmp/corefiles
RUN chmod -R a+w /tmp/corefiles
ADD enable-coredump.sh /scripts/enable-coredump.sh

# Add the wait script to the image: note SHA 672a28f0509433e3b4b9bcd4d9cd7668cea7e31a has been reviewed and should not
# be changed without an appropriate code review.
ADD https://raw.githubusercontent.com/eficode/wait-for/672a28f0509433e3b4b9bcd4d9cd7668cea7e31a/wait-for /scripts/wait-for.sh
RUN chmod +x /scripts/wait-for.sh

# Installing vegeta
RUN curl -L -o /tmp/vegeta.tar.gz https://github.com/tsenart/vegeta/releases/download/v12.8.4/vegeta_12.8.4_linux_amd64.tar.gz \
    && tar -C /usr/bin -zxvf /tmp/vegeta.tar.gz vegeta \
    && rm /tmp/vegeta.tar.gz

# Installing composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Preparing PHP
RUN echo "date.timezone = UTC" > "/etc/php.d/00-adjust-timezones.ini"

# Preparing PHP-FPM
RUN mkdir -p /usr/local/etc/php-fpm.d
ADD php-fpm.conf /usr/local/etc/php-fpm.conf

# Preparing NGINX
RUN groupadd www-data
RUN adduser -M --system -g www-data www-data
ADD nginx.conf /etc/nginx/nginx.conf
ADD nginx.site.conf /etc/nginx/conf.d/default.conf

# Preparing HTTPD
ADD apache.php.conf /etc/httpd/conf.d/php.conf
RUN sed -i 's/Listen 80/Listen 81/' /etc/httpd/conf/httpd.conf
RUN echo "CoreDumpDirectory /tmp/corefiles" >> /etc/httpd/conf/httpd.conf

ADD run.sh /scripts/run.sh
ADD prepare.sh /scripts/prepare.sh

WORKDIR /var/www/html

ENV COMPOSER_CACHE_DIR /composer-cache
RUN mkdir -p ${COMPOSER_CACHE_DIR}
ENV COMPOSER_VENDOR_DIR /composer-vendor
RUN mkdir -p ${COMPOSER_VENDOR_DIR}

CMD [ "bash", "/scripts/run.sh" ]
