ARG PHP_MAJOR_MINOR

FROM registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_buster

USER root

# Enabling Opcache, which is disabled by default
RUN for DIR in /opt/php/*; do (echo "zend_extension=opcache.so"; echo "opcache.enable_cli=1") > $DIR/conf.d/opcache.ini; done

# don't execute an asan *binary* under qemu
RUN mv /opt/php/debug/bin/php-config /opt/php/debug/bin/php-config-debug; cp /opt/php/debug-zts-asan/bin/php-config /opt/php/debug/bin/php-config

# install redis for randomized tests
RUN echo "extension=redis" >> $(php-config --ini-dir)/redis.ini

# Igbinary
RUN set -eux; \
    pecl install "igbinary"; \
    for DIR in /opt/php/*; do echo "extension=igbinary.so" > $DIR/conf.d/igbinary.ini; done

RUN mv /opt/php/debug/bin/php-config-debug /opt/php/debug/bin/php-config
RUN switch-php debug-zts-asan

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
    curl -L --output golang.tar.gz https://go.dev/dl/go1.22.1.linux-${GO_ARCHITECTURE}.tar.gz; \
    rm -rf /usr/local/go && tar -C /usr/local -xzf golang.tar.gz;
#    - Download vegeta
RUN set -eux; \
    curl -L --output src-vegeta.tar.gz https://github.com/tsenart/vegeta/archive/refs/tags/v12.8.4.tar.gz; \
    tar -xvf src-vegeta.tar.gz; \
    cd vegeta*; \
    /usr/local/go/bin/go install; \
    cp /root/go/bin/vegeta /usr/local/bin

# Preparing PHP-FPM
RUN for DIR in /opt/php/*; do mkdir -p $DIR/etc/php-fpm.d; done
ADD php-fpm.conf /home/circleci/php-fpm.conf
RUN for DIR in /opt/php/*; do cp /home/circleci/php-fpm.conf $DIR/etc/; mv $DIR/etc/php-fpm.d/www.conf.default $DIR/etc/php-fpm.d/www.conf; done; rm /home/circleci/php-fpm.conf

# Preparing NGINX
RUN groupadd nginx
RUN adduser --system --group nginx
ADD nginx.conf /etc/nginx/nginx.conf
ADD nginx.site.conf /etc/nginx/conf.d/default.conf

# Preparing HTTPD
ADD apache.php.conf /etc/apache2/conf-enabled/docker-php.conf
RUN sed -i 's/Listen 80/Listen 81/' /etc/apache2/ports.conf
RUN echo "CoreDumpDirectory /tmp/corefiles" >> /etc/apache2/apache.conf
RUN mkdir -p /etc/httpd/conf.d /var/log/httpd
RUN ln -s /etc/httpd/conf.d/www.conf /etc/apache2/sites-enabled/www.conf
RUN sed -i 's/apache2/httpd/' /etc/apache2/envvars

ADD run.sh /scripts/run.sh
ADD prepare.sh /scripts/prepare.sh

# actually bind the sidecar error output to docker out
ENV _DD_DEBUG_SIDECAR_LOG_METHOD=file:///proc/1/fd/2
ENV DD_SPAWN_WORKER_USE_EXEC=1

WORKDIR /var/www/html

ENV COMPOSER_CACHE_DIR /composer-cache
RUN mkdir -p ${COMPOSER_CACHE_DIR}
ENV COMPOSER_VENDOR_DIR /composer-vendor
RUN mkdir -p ${COMPOSER_VENDOR_DIR}

CMD [ "bash", "/scripts/run.sh" ]
