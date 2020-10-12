FROM datadog/dd-trace-ci:buster AS base

# Set up PHP master branch that can be built by running `install-php-master`
RUN set -eux; \
    git config --global user.email "test@example.com"; \
    git config --global user.name "Test User"; \
    cd $PHP_SRC_DIR; \
    git clone --depth 1 --branch master https://github.com/php/php-src.git master;

COPY install-php-master /usr/local/bin/
RUN install-php-master

# Install Composer
RUN set -eux; \
    curl -q https://raw.githubusercontent.com/composer/getcomposer.org/76a7060ccb93902cd7576b67264ad91c8a2700e2/web/installer | \
    sudo php -- --filename=composer --install-dir=/usr/local/bin;

COPY welcome /etc/motd

CMD ["php-fpm", "-F"]
