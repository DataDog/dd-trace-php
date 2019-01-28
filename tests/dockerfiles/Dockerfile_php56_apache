FROM php:5.6-apache

RUN apt-get update \
# Install base packages
    && apt-get install -y \
        curl \
        wget \
        mysql-client \
        git \
        gnupg2 \
        zlib1g-dev \
        unzip \
        libmcrypt-dev \
        vim \
# Install relevant php extensions
    && docker-php-source extract \
    && docker-php-ext-install mcrypt \
    && docker-php-ext-install pdo_mysql \
    && docker-php-ext-install pdo \
    && docker-php-ext-install zip \
    && docker-php-source delete \
# Install composer
    && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php  --install-dir="/usr/bin" --filename=composer \
    && php -r "unlink('composer-setup.php');" \
    && composer self-update \
# Remove installation cache
    && rm -rf /var/lib/apt/lists/*

# Install DDTrace deb
#ARG DDTRACE_URL=https://11950-119990860-gh.circle-artifacts.com/0/datadog-php-tracer_0.11.0-beta_amd64.deb
#RUN wget -O datadog-php-tracer.deb ${DDTRACE_URL} \
#    && dpkg -i datadog-php-tracer.deb \
#    && apt-get install -f

RUN a2enmod rewrite

COPY ./tests/dockerfiles/php.enanche.ini /usr/local/etc/php/conf.d

VOLUME /var/www

COPY build/packages/datadog-php-tracer_0.11.0-beta_amd64.deb /var/dd-extension/datadog-php-tracer.deb
WORKDIR /var/dd-extension
RUN dpkg -i datadog-php-tracer.deb

WORKDIR /var/www
