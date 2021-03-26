ARG PHP_VERSION

FROM php:${PHP_VERSION}-cli

ENV DEBIAN_FRONTEND=noninteractive

WORKDIR /var/app
COPY ./script.php script.php
COPY ./local-run.sh local-run.sh

CMD bash
