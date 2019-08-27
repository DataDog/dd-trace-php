FROM debian:buster

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

ARG SYMFONY_VERSION_TAG=v4.3.4
RUN set -xe; \
    git clone --branch ${SYMFONY_VERSION_TAG} https://github.com/symfony/symfony /home/symfony

RUN set -xe; \
    cd /home/symfony; \
    export COMPOSER_MEMORY_LIMIT=-1; \
    # php .github/build-packages.php HEAD^ src/Symfony/Bridge/PhpUnit src/Symfony/Contracts; \
    export SYMFONY_DEPRECATIONS_HELPER=weak;\
      cp composer.json composer.json.orig; \
      echo '{\n"require":{'"$(grep phpunit-bridge composer.json)"'"php":"*"},"minimum-stability":"dev"}' > composer.json; \
      php .github/build-packages.php HEAD^ $(find src/Symfony -mindepth 3 -type f -name composer.json -printf '%h\n'); \
      mv composer.json composer.json.phpunit; \
      mv composer.json.orig composer.json;\
    export SYMFONY_VERSION=$(cat composer.json | grep '^ *"dev-master". *"[1-9]' | grep -o '[0-9.]*');\
    export export SYMFONY_REQUIRE=">=$SYMFONY_VERSION";\
    composer global require --no-progress --no-scripts --no-plugins symfony/flex dev-master;\
    export COMPOSER_ROOT_VERSION=$SYMFONY_VERSION.x-dev; \
    mv composer.json.phpunit composer.json; \
    # export PHPUNIT_X="php /home/symfony/phpunit --exclude-group tty,benchmark,intl-data,legacy";
    # export COMPOSER_ROOT_VERSION=4.3.4; \
    # export SYMFONY_REQUIRE="4.3.4"; \
    # sed -i -e 's/4.3-dev/v4.3.4/g' composer.json; \
    # sed -i -e 's/self.version/~4.3/g' composer.json; \
    # composer config -- version 4.3.4; \
    # composer config -- prefer-lowest true; \
    # composer global require --no-progress --no-scripts --no-plugins symfony/flex dev-master; \
    composer update; \
    php ./phpunit install; \
    echo

RUN set -xe; \
      apt-get install -y parallel
RUN set -xe; \
  cd /home/symfony; \
    COMPONENTS=$(find src/Symfony -mindepth 3 -type f -name phpunit.xml.dist -not -wholename '*/Bridge/PhpUnit/*' -printf '%h\n'); \
    echo "$COMPONENTS" | parallel --gnu 'cd {}; composer update'


WORKDIR /home/symfony
ENV COMPOSER_MEMORY_LIMIT -1

ADD entrypoint.sh /usr/local/bin/entrypoint.sh

ENTRYPOINT [ "/usr/local/bin/entrypoint.sh" ]

CMD [ "php /home/symfony/phpunit --exclude-group tty,benchmark,intl-data,legacy" ]
