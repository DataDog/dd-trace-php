ARG PHP_VERSION

FROM testing-environment:ubuntu-php${PHP_VERSION}

ARG APP_PATH

ADD $APP_PATH /var/www/html/
RUN if [ -f "composer.json" ]; then composer update; fi
