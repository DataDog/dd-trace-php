FROM debian:bookworm AS base

ENV DEBIAN_FRONTEND=noninteractive

RUN set -xe; \
  apt-get update; \
  apt-get install -y curl gnupg vim zip git; \
  echo "deb https://packages.sury.org/php/ bookworm main" | tee /etc/apt/sources.list.d/php.list; \
  curl -L -o - https://packages.sury.org/php/apt.gpg | apt-key add -;\
  apt-get update; \
  apt-get clean; \
  git config --global user.email "example@example.com"; \
  git config --global user.name "Patch Maker";

RUN set -xe; \
    apt-get update; \
    apt-get install -y gdb valgrind; \
    apt-get clean;

RUN set -xe; \
    apt-get update; \
    apt-get install -y $(for V in 7.0 7.1 7.2 7.3; do \
        echo \
        php${V}-fpm \
        php${V}-apcu \
        php${V}-ctype \
        php${V}-curl \
        php${V}-dom \
        php${V}-gd \
        php${V}-iconv \
        # php${V}-imagick \
        php${V}-json \
        php${V}-intl \
        php${V}-fileinfo\
        php${V}-mbstring \
        php${V}-opcache \
        php${V}-pdo \
        php${V}-mysqli \
        php${V}-xml \
        php${V}-phar \
        php${V}-tokenizer \
        php${V}-simplexml \
        # php${V}-xdebug \
        php${V}-zip \
        php${V}-xmlwriter \
        php${V}-mysql \
        php${V}-sqlite3 \
        php${V}-memcached \
        php${V}-amqp \
        php${V}-dev \
        # php${V}-pear \
        php${V}-bcmath; \
    done; \
    ); \
    apt-get clean;

RUN set -xe; \
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');";\
    php -r "if (hash_file('sha384', 'composer-setup.php') === '$(curl -L -o - https://composer.github.io/installer.sig)') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"; \
    php composer-setup.php --2.2; \
    php -r "unlink('composer-setup.php');"; \
    mv composer.phar /usr/local/bin/composer;

ADD switch_php.sh /usr/local/bin/switch_php

RUN set -xe; \
    for V in 7.2 7.3; do \
      switch_php ${V}; \
      rm /usr/share/php/.registry/.channel.pecl.php.net/redis.reg || true; \
      pecl install redis-5.3.7; \
    done

ENV COMPOSER_MEMORY_LIMIT -1
ADD entrypoint.sh /usr/local/bin/entrypoint.sh
ADD flow/20-redis.ini /etc/php/7.3/cli/conf.d/20-redis.ini
ADD flow/20-redis.ini /etc/php/7.2/cli/conf.d/20-redis.ini

ENTRYPOINT [ "/usr/local/bin/entrypoint.sh" ]
CMD [ "bash" ]

# FROM base AS symfony
# ENV COMPOSER_MEMORY_LIMIT -1
# ARG SYMFONY_VERSION_TAG=v4.3.4

# RUN set -xe; \
#     git clone --depth 1 --branch ${SYMFONY_VERSION_TAG} https://github.com/symfony/symfony /home/symfony; \
#     cd /home/symfony; \
#     composer update --prefer-dist; \
#     php ./phpunit install

# WORKDIR /home/symfony/
# ADD symfony/${SYMFONY_VERSION_TAG}.patch ./
# RUN set -xe; \
#     # add vendor to git to allow easy patching of vendor files too
#     git add -f vendor; \
#     git commit -m "vendor"; \
#     git apply *.patch

# ENV SYMFONY_DEPRECATIONS_HELPER=disabled=1
# ADD symfony/run.sh ./
# CMD [ "./run.sh" ]

FROM base AS laravel
ARG LARAVEL_VERSION_TAG=v5.8.17
RUN set -xe; \
    git clone --depth 1 --branch ${LARAVEL_VERSION_TAG} https://github.com/laravel/framework /home/laravel; \
    cd /home/laravel; \
    export COMPOSER_MEMORY_LIMIT=-1; \
    composer install --prefer-dist

WORKDIR /home/laravel
ADD laravel/patch.patch ./
ENV DD_SERVICE=laravel
RUN set -xe; \
    git apply *.patch

CMD ["php", "./vendor/bin/phpunit", "-v"]

FROM base AS flow
ARG FLOW_BUILD_TOOLS_VERISON=5.2
RUN set -xe; \
    git clone https://github.com/neos/flow-development-distribution.git -b ${FLOW_BUILD_TOOLS_VERISON} /home/flow; \
    cd /home/flow; \
    composer config --no-plugins allow-plugins.neos/composer-plugin true; \
    composer update --no-progress --no-interaction

WORKDIR /home/flow
RUN set -xe; \
    mkdir -p ../../neos/; \
    git clone --branch ${FLOW_BUILD_TOOLS_VERISON} https://github.com/neos/flow-development-collection ../../neos/flow-development-collection; \
    export NEOS_TARGET_REPOSITORY=neos/flow-development-collection; \
    export NEOS_TARGET_VERSION=${FLOW_BUILD_TOOLS_VERISON}; \
    export TRAVIS_REPO_SLUG=neos/flow-development-collection; \
    php Build/BuildEssentials/TravisCi/ComposerManifestUpdater.php .
RUN set -xe; \
    sed -e 's/dev-travis as //g' -i composer.json; \
    composer update --no-progress --no-interaction; \
    rm -f Configuration/Routes.yaml; \
    cp Configuration/Settings.yaml.example Configuration/Settings.yaml; \
    set -e 's/127.0.0.1/mysql/g' -i Configuration/Settings.yaml; \
    Build/BuildEssentials/TravisCi/SetupDatabase.sh; \
    cp Configuration/Settings.yaml Configuration/Testing/; \
    FLOW_CONTEXT=Testing/Behat ./flow behat:setup;
RUN set -xe; \
  apt-get update; \
  apt-get install -y default-mysql-client;

RUN set -xe; \
    rm -rf ./Packages/Application/Neos.Behat/.git \
    ./Packages/Application/Neos.Welcome/.git \
    ./Packages/Framework/.git \
    ./Build/BuildEssentials/.git \
    ./Build/Behat/vendor/behat/mink-extension/.git \
    ./Build/Behat/vendor/behat/behat/.git; \
    git add -f Build; \
    git add -f Packages; \
    git commit -m "pre patch"

ADD flow/30.tz.ini /etc/php/7.3/cli/conf.d/30-tz.ini
ADD flow/30.tz.ini /etc/php/7.2/cli/conf.d/30-tz.ini
ADD flow/30.tz.ini /etc/php/7.1/cli/conf.d/30-tz.ini
ADD flow/30.tz.ini /etc/php/7.0/cli/conf.d/30-tz.ini
ADD flow/Settings.yaml Configuration/Settings.yaml
ADD flow/Settings.yaml Configuration/Testing/Settings.yaml
ADD flow/run.sh ./
ADD flow/patch.patch ./
ADD flow/tty.patch ./

RUN set -xe; \
    git apply *.patch

CMD [ "./run.sh" ]

FROM base AS wordpress
ENV DD_SERVICE=wordpress
ARG WORDPRESS_VERSION_TAG=4.8.10
RUN set -xe; \
  apt-get update; \
  apt-get install -y default-mysql-client; \
  apt-get clean

RUN set -xe; \
    git clone --depth 1 --branch ${WORDPRESS_VERSION_TAG} git://develop.git.wordpress.org/ /home/wordpress; \
    cd /home/wordpress; \
    switch_php 7.0; \
    composer require --dev phpunit/phpunit ^6

WORKDIR /home/wordpress
ADD wordpress/wp-tests-config.php ./
ADD wordpress/run.sh ./
ADD wordpress/${WORDPRESS_VERSION_TAG}.patch ./

RUN set -xe; \
    git apply *.patch

CMD [ "./run.sh" ]

########################################################################################################################
## phpredis/phpredis frameworks tests
########################################################################################################################
FROM base AS phpredis
ARG PHPREDIS_VERSION_TAG

RUN set -xe; \
    git clone --depth 1 --branch ${PHPREDIS_VERSION_TAG} https://github.com/phpredis/phpredis.git \
        /home/phpredis-${PHPREDIS_VERSION_TAG};

WORKDIR /home/phpredis-${PHPREDIS_VERSION_TAG}

ENV DD_SERVICE=phpredis-${PHPREDIS_VERSION_TAG}

# Installing the correct version of redis
RUN pecl uninstall redis
RUN printf 'no' | pecl install -f redis-${PHPREDIS_VERSION_TAG}

# Applying patches
ADD phpredis/${PHPREDIS_VERSION_TAG}/patch.patch /home/phpredis-${PHPREDIS_VERSION_TAG}/patch.patch
RUN set -xe; \
    git apply *.patch

WORKDIR /home/phpredis-${PHPREDIS_VERSION_TAG}
ADD phpredis/${PHPREDIS_VERSION_TAG}/run.sh ./run.sh
CMD [ "./run.sh" ]

########################################################################################################################
## mongodb-driver frameworks tests
########################################################################################################################
FROM base AS mongodb-driver
ARG MONGODB_VERSION_TAG

RUN set -xe; \
    git clone --depth 1 --branch ${MONGODB_VERSION_TAG} https://github.com/mongodb/mongo-php-driver.git \
        /home/mongodb-driver-${MONGODB_VERSION_TAG};

WORKDIR /home/mongodb-driver-${MONGODB_VERSION_TAG}

ENV DD_SERVICE=mongodb-driver-${MONGODB_VERSION_TAG}

# Building mongodb-driver
#   https://github.com/mongodb/mongo-php-driver/blob/master/CONTRIBUTING.md
RUN git submodule update --init
RUN phpize
RUN ./configure --enable-mongodb-developer-flags
RUN make clean
RUN make all
RUN make install

ADD mongodb-driver/run.sh ./run.sh

CMD [ "./run.sh" ]
