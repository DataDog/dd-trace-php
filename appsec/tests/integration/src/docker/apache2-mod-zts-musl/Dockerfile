ARG DOCKER_REGISTRY=docker.io
FROM ${DOCKER_REGISTRY}/library/php:8.5-zts-alpine

# The official image matrix has no Apache + ZTS or Apache + Alpine variant.
# Rebuild the same PHP source and configuration with the Apache SAPI enabled so
# it can run under event MPM.
RUN apk add --no-cache apache2 bash libstdc++ && \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        apache2-dev \
        argon2-dev \
        coreutils \
        curl-dev \
        gnu-libiconv-dev \
        libsodium-dev \
        libxml2-dev \
        linux-headers \
        oniguruma-dev \
        openssl-dev \
        readline-dev \
        sqlite-dev && \
    rm -f /usr/include/iconv.h && \
    export \
        CFLAGS="$PHP_CFLAGS" \
        CPPFLAGS="$PHP_CPPFLAGS" \
        LDFLAGS="$PHP_LDFLAGS" \
        PHP_BUILD_PROVIDER='https://github.com/docker-library/php' \
        PHP_UNAME='Linux - Docker' && \
    docker-php-source extract && \
    cd /usr/src/php && \
    gnuArch="$(dpkg-architecture --query DEB_BUILD_GNU_TYPE)" && \
    ./configure \
        --build="$gnuArch" \
        --sysconfdir="${PHP_INI_DIR%/php}" \
        --with-config-file-path="$PHP_INI_DIR" \
        --with-config-file-scan-dir="$PHP_INI_DIR/conf.d" \
        --enable-option-checking=fatal \
        --with-mhash \
        --with-pic \
        --enable-mbstring \
        --enable-mysqlnd \
        --with-password-argon2 \
        --with-sodium=shared \
        --with-pdo-sqlite=/usr \
        --with-sqlite3=/usr \
        --with-curl \
        --with-iconv=/usr \
        --with-openssl \
        --with-readline \
        --with-zlib \
        --disable-phpdbg \
        --with-pear \
        --disable-cgi \
        --enable-zts \
        --disable-zend-signals \
        --with-apxs2=/usr/bin/apxs && \
    make -j "$(nproc)" && \
    make install && \
    cd / && \
    docker-php-source delete && \
    apk del --no-network .build-deps && \
    mkdir -p /etc/php /tmp/logs/apache2 /var/www/public && \
    touch /etc/php/php.ini && \
    sed -i \
        -e '/^LoadModule php_module /d' \
        -e 's/^LoadModule mpm_prefork_module/#LoadModule mpm_prefork_module/' \
        -e 's/^#LoadModule mpm_event_module/LoadModule mpm_event_module/' \
        -e 's/^#LoadModule auth_digest_module/LoadModule auth_digest_module/' \
        -e 's/^#LoadModule rewrite_module/LoadModule rewrite_module/' \
        /etc/apache2/httpd.conf

# AppSecContainer overlays /var/www after startup. Alpine's default relative
# module paths resolve below that directory because it is Apache's ServerRoot.
RUN sed -i \
        -e 's@ modules/@ /usr/lib/apache2/@' \
        -e 's@/var/www/localhost/htdocs@/var/www/public@g' \
        -e 's@^ErrorLog logs/error.log@ErrorLog /tmp/logs/apache2/error.log@' \
        -e 's@CustomLog logs/access.log@CustomLog /tmp/logs/apache2/access.log@' \
        /etc/apache2/httpd.conf

COPY php.conf /etc/apache2/conf.d/php.conf
COPY start.sh /start.sh
RUN chmod +x /start.sh

WORKDIR /project

CMD ["/start.sh"]
