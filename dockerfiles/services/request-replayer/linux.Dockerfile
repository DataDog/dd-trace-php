FROM composer:2.11@sha256:ca11f643d7791ebdd90a34fc20caf11380d434007ae539a9ea75cd9212e13dca
COPY --from=mlocati/php-extension-installer:2.13.2 /usr/bin/install-php-extensions /usr/local/bin/

# php-gmp is required to handle intermediate values for 64bit ids
# while unpacking msgpack payloads.
RUN install-php-extensions gmp

WORKDIR /var/www

COPY src /var/www

EXPOSE 80
EXPOSE 80/udp

RUN composer install

CMD [ "php", "-S", "0.0.0.0:80", "index.php" ]
