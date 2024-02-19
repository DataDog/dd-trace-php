FROM composer:2

# php-gmp is required to handle intermediate values for 64bit ids
# while unpacking msgpack payloads.
RUN install-php-extensions gmp

WORKDIR /var/www

COPY src /var/www

EXPOSE 80

RUN composer install

CMD [ "php", "-S", "0.0.0.0:80", "index.php" ]
