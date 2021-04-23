FROM datadog/dd-trace-ci:buster AS base

# Set up PHP master branch that can be built by running `install-php-master`
RUN set -eux; \
    git config --global user.email "test@example.com"; \
    git config --global user.name "Test User"; \
    cd $PHP_SRC_DIR; \
    git clone --depth 1 --branch master https://github.com/php/php-src.git master;

COPY install-php-master /usr/local/bin/
RUN install-php-master


COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY welcome /etc/motd

CMD ["php-fpm", "-F"]
