ARG PHP_MAJOR_MINOR

FROM datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_centos-7

# Getting the latest nginx
RUN echo $'[nginx]\nname=nginx repo\nbaseurl=https://nginx.org/packages/mainline/centos/7/$basearch/\ngpgcheck=0\nenabled=1' >> /etc/yum.repos.d/nginx.repo

RUN set -eux; \
    yum install -y \
        elinks \
        gdb \
        nc \
        nginx; \
    yum install -y --enablerepo=base-debuginfo httpd; \
    yum clean all; \
    rm -rf /var/cache/yum

# Enabling Opcache, which is disabled by default
RUN for DIR in /opt/php/*; do (echo "zend_extension=opcache.so"; echo "opcache.enable_cli=1") > $DIR/conf.d/opcache.ini; done

# Igbinary
RUN set -eux; \
    pecl install "igbinary"; \
    for DIR in /opt/php/*; do echo "extension=igbinary.so" > $DIR/conf.d/igbinary.ini; done

# Memached
RUN set -eux; \
    printf 'yes' | pecl install "memcached"; \
    for DIR in /opt/php/*; do echo "extension=memcached.so" > $DIR/conf.d/memcached.ini; done

# Redis
RUN set -eux; \
    printf 'yes' | pecl install "redis"; \
    for DIR in /opt/php/*; do echo "extension=redis.so" > $DIR/conf.d/redis.ini; done

# Create coredumps folder
# If not generated, see: https://fromdual.com/hunting-the-core
RUN mkdir -p /tmp/corefiles
RUN chmod -R a+w /tmp/corefiles
ADD enable-coredump.sh /scripts/enable-coredump.sh

# Add the wait script to the image: note SHA 672a28f0509433e3b4b9bcd4d9cd7668cea7e31a has been reviewed and should not
# be changed without an appropriate code review.
ADD https://raw.githubusercontent.com/eficode/wait-for/672a28f0509433e3b4b9bcd4d9cd7668cea7e31a/wait-for /scripts/wait-for.sh
RUN chmod +x /scripts/wait-for.sh

# Installing vegeta (not released for aarch64)
#    - Install golang
RUN set -eux; \
    GO_ARCHITECTURE=$(if [ `uname -m` = "aarch64" ]; then echo "arm64"; else echo "amd64"; fi); \
    curl -L --output golang.tar.gz https://go.dev/dl/go1.18.3.linux-${GO_ARCHITECTURE}.tar.gz; \
    rm -rf /usr/local/go && tar -C /usr/local -xzf golang.tar.gz;
#    - Download vegeta
RUN set -eux; \
    curl -L --output src-vegeta.tar.gz https://github.com/tsenart/vegeta/archive/refs/tags/v12.8.4.tar.gz; \
    tar -xvf src-vegeta.tar.gz; \
    cd vegeta*; \
    /usr/local/go/bin/go install; \
    cp /root/go/bin/vegeta /usr/local/bin

# Installing composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Preparing PHP
RUN for DIR in /opt/php/*; do echo "date.timezone = UTC" > $DIR/conf.d/00-adjust-timezones.ini; done

# Preparing PHP-FPM
RUN for DIR in /opt/php/*; do mkdir -p $DIR/etc/php-fpm.d; done
ADD php-fpm.conf /home/circleci/php-fpm.conf
RUN for DIR in /opt/php/*; do cp /home/circleci/php-fpm.conf $DIR/etc/; done; rm /home/circleci/php-fpm.conf

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
