FROM composer:2

# RUN apt-get update \
#   && apt-get install -y git unzip \
#   && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

# RUN set -eux; \
#   php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"; \
#   php -r "if (hash_file('sha384', 'composer-setup.php') === '55ce33d7678c5a611085589f1f3ddf8b3c52d662cd01d4ba75c0ee0459970c2200a51f492d557530c71c15d8dba01eae') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"; \
#   php composer-setup.php --install-dir=/usr/local/bin --filename=composer; \
#   php -r "unlink('composer-setup.php');"

COPY src /var/www

EXPOSE 80

RUN composer install

CMD [ "php", "-S", "0.0.0.0:80", "index.php" ]
