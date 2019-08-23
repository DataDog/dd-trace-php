FROM debian:buster AS base

ENV DEBIAN_FRONTEND=noninteractive
RUN set -xe; \
    apt-get update; \
    apt-get install -y git zip\
        php-fpm \
        php-apcu \
        php-ctype \
        php-curl \
        php-dom \
        php-gd \
        php-iconv \
        php-imagick \
        php-json \
        php-intl \
        php-fileinfo\
        php-mbstring \
        php-opcache \
        php-pdo \
        php-mysqli \
        php-xml \
        php-phar \
        php-tokenizer \
        php-simplexml \
        php-xdebug \
        php-zip \
        php-xmlwriter \
        php-redis \
        php-mysql \
        php-sqlite3 \
        php-memcached \
        php-amqp \
        curl \
        vim;

RUN set -xe; \
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');";\
    php -r "if (hash_file('sha384', 'composer-setup.php') === 'a5c698ffe4b8e849a443b120cd5ba38043260d5c4023dbf93e1558871f1f07f58274fc6f4c93bcfd858c6bd0775cd8d1') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"; \
    php composer-setup.php; \
    php -r "unlink('composer-setup.php');"; \
    mv composer.phar /usr/local/bin/composer;
ENV COMPOSER_MEMORY_LIMIT -1
ADD entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT [ "/usr/local/bin/entrypoint.sh" ]
CMD [ "bash" ]

FROM base AS symfony

ARG SYMFONY_VERSION_TAG=v4.3.3
RUN set -xe; \
    git clone --depth 1 --branch ${SYMFONY_VERSION_TAG} https://github.com/symfony/symfony /home/symfony; \
    cd /home/symfony; \
    export COMPOSER_MEMORY_LIMIT=-1; \
    composer install --prefer-dist; \
    php ./phpunit --list-tests

WORKDIR /home/symfony

FROM base AS laravel
ARG LARAVEL_VERSION_TAG=v5.8.17
RUN set -xe; \
    git clone --depth 1 --branch ${LARAVEL_VERSION_TAG} https://github.com/laravel/framework /home/laravel; \
    apt-get -y install php-bcmath;

RUN set -xe; \
    cd /home/laravel; \
    export COMPOSER_MEMORY_LIMIT=-1; \
    composer install --prefer-dist
WORKDIR /home/laravel
