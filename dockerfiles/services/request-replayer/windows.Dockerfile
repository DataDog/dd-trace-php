FROM datadog/dd-trace-ci:php-8.2_windows

WORKDIR /var/www

COPY src /var/www

EXPOSE 80
EXPOSE 80/udp

RUN composer install

CMD [ "php", "-S", "0.0.0.0:80", "index.php" ]
