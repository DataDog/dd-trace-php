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
        php-mysql \
        php-sqlite3 \
        php-memcached \
        php-amqp \
        php-dev \
        php-pear \
        php-bcmath \
        curl \
        vim; \
    pecl install redis; \
    git config --global user.email "example@example.com"; \
    git config --global user.name "Patch Maker";

RUN set -xe; \
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');";\
    php -r "if (hash_file('sha384', 'composer-setup.php') === 'a5c698ffe4b8e849a443b120cd5ba38043260d5c4023dbf93e1558871f1f07f58274fc6f4c93bcfd858c6bd0775cd8d1') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"; \
    php composer-setup.php; \
    php -r "unlink('composer-setup.php');"; \
    mv composer.phar /usr/local/bin/composer;

ENV COMPOSER_MEMORY_LIMIT -1
ADD entrypoint.sh /usr/local/bin/entrypoint.sh
ADD 20-redis.ini /etc/php/7.3/cli/conf.d/20-redis.ini

ENTRYPOINT [ "/usr/local/bin/entrypoint.sh" ]
CMD [ "bash" ]

FROM base AS symfony
ENV COMPOSER_MEMORY_LIMIT -1
ARG SYMFONY_VERSION_TAG=v4.3.4


RUN set -xe; \
    git clone --depth 1 --branch ${SYMFONY_VERSION_TAG} https://github.com/symfony/symfony /home/symfony; \
    cd /home/symfony; \
    composer update --prefer-dist; \
    php ./phpunit install

WORKDIR /home/symfony/
ADD symfony_${SYMFONY_VERSION_TAG}.patch ./
RUN set -xe; \
    # add vendor to git to allow easy patching of vendor files too
    git add -f vendor; \
    git commit -m "vendor"; \
    git apply *.patch

ENV SYMFONY_DEPRECATIONS_HELPER=disabled=1
ADD symfony_run.sh ./
CMD [ "./symfony_run.sh" ]

FROM base AS laravel
ARG LARAVEL_VERSION_TAG=v5.8.17
RUN set -xe; \
    git clone --depth 1 --branch ${LARAVEL_VERSION_TAG} https://github.com/laravel/framework /home/laravel; \
    cd /home/laravel; \
    export COMPOSER_MEMORY_LIMIT=-1; \
    composer install --prefer-dist
WORKDIR /home/laravel

FROM base AS flow
ARG FLOW_VERSION_TAG=5.2.6
ARG FLOW_BUILD_TOOLS_VERISON=5.2
RUN set -xe; \
    git clone https://github.com/neos/flow-development-distribution.git -b ${FLOW_BUILD_TOOLS_VERISON} /home/flow; \
    # git clone --depth 1 --branch ${FLOW_VERSION_TAG} https://github.com/neos/flow-development-collection /home/flow; \
    cd /home/flow; \
    composer update --no-progress --no-interaction

WORKDIR /home/flow
RUN set -xe; \
    mkdir -p ../../neos/; \
    git clone --depth 1 --branch ${FLOW_VERSION_TAG} https://github.com/neos/flow-development-collection ../../neos/flow-development-collection; \
    export NEOS_TARGET_REPOSITORY=neos/flow-development-collection; \
    export NEOS_TARGET_VERSION=5.2; \
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
    FLOW_CONTEXT=Testing/Behat ./flow behat:setup; \
    apt-get install -y default-mysql-client

ADD flow/30.tz.ini /etc/php/7.3/cli/conf.d/30-tz.ini
ADD flow/Settings.yaml Configuration/Settings.yaml
ADD flow/Settings.yaml Configuration/Testing/Settings.yaml
ADD flow/run.sh ./
# RUN set -xe; \

# CMD ["bin/phpunit", "--colors", "-c", "Build/BuildEssentials/PhpUnit/UnitTests.xml"]
# CMD ["bin/behat", "--ansi", "--stop-on-failure", "-f", "progress", "-c", "Packages/Framework/Neos.Flow/Tests/Behavior/behat.yml.dist"]
# CMD ["bin/phpunit",  "--colors",  "--stop-on-failure",  "-c", "Build/BuildEssentials/PhpUnit/FunctionalTests.xml", "--testsuite", "Framework tests"]
CMD [ "./run.sh" ]
# RUN bin/phpunit --colors -c Build/BuildEssentials/PhpUnit/UnitTests.xml
#

RUN set -xe; \
  echo "deb https://packages.sury.org/php/ buster main" | tee /etc/apt/sources.list.d/php.list; \
  curl -L -o - https://packages.sury.org/php/apt.gpg | apt-key add -;\
  apt-get update
