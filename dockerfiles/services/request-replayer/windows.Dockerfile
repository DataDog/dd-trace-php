ARG CI_REGISTRY_IMAGE=datadog/dd-trace-ci
FROM ${CI_REGISTRY_IMAGE}:php-8.2_windows

WORKDIR /var/www

COPY src /var/www

EXPOSE 80
EXPOSE 80/udp

RUN composer install

CMD [ "php", "-S", "0.0.0.0:80", "index.php" ]
