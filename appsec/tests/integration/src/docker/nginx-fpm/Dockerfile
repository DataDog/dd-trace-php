ARG PHP_VERSION
ARG VARIANT

FROM datadog/dd-appsec-php-ci:php-$PHP_VERSION-$VARIANT

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg; \
    curl -fsSL --retry 5 --retry-delay 2 -o /tmp/nginx_signing.key https://nginx.org/keys/nginx_signing.key; \
    gpg --show-keys --with-colons /tmp/nginx_signing.key | grep -q 'fpr:::::::::573BFD6B3D8FBC641079A6ABABF5BD827BD9BF62:'; \
    gpg --dearmor -o /usr/share/keyrings/nginx-archive-keyring.gpg /tmp/nginx_signing.key; \
    rm /tmp/nginx_signing.key; \
    . /etc/os-release; \
    echo "deb [signed-by=/usr/share/keyrings/nginx-archive-keyring.gpg] https://nginx.org/packages/debian ${VERSION_CODENAME} nginx" > /etc/apt/sources.list.d/nginx.list; \
    printf 'Package: nginx\nPin: origin nginx.org\nPin-Priority: 900\n' > /etc/apt/preferences.d/99nginx; \
    apt-get update; \
    apt-get install -y --no-install-recommends nginx; \
    dpkg --compare-versions "$(dpkg-query -W -f='${Version}' nginx)" ge 1.30.7; \
    rm -rf /var/lib/apt/lists/*

RUN usermod -a -G linux_users www-data

RUN rm -rf /var/www/html

ADD nginx-fpm/entrypoint.sh /
ADD nginx-fpm/default /etc/nginx/conf.d/default.conf

RUN nginx -t

ADD fpm-common/php-fpm.conf /etc/
RUN mkdir /etc/php-fpm.d/
ADD fpm-common/www.conf /etc/php-fpm.d/

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]

