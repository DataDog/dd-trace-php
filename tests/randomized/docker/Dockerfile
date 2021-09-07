FROM centos:7

ARG PHP_MAJOR
ARG PHP_MINOR

# Getting the latest nginx
RUN echo $'[nginx]\nname=nginx repo\nbaseurl=https://nginx.org/packages/mainline/centos/7/$basearch/\ngpgcheck=0\nenabled=1' >> /etc/yum.repos.d/nginx.repo

RUN \
    rpm -Uvh https://dl.fedoraproject.org/pub/epel/epel-release-latest-7.noarch.rpm \
    && yum install -y elinks wget nginx httpd unzip gdb git nc \
    && rpm -Uvh http://rpms.famillecollet.com/enterprise/remi-release-7.rpm \
    && yum --enablerepo=remi-php${PHP_MAJOR}${PHP_MINOR} install -y \
            php-cli \
            php-curl \
            php-fpm \
            php-memcached \
            php-opcache \
            php-pdo_mysql \
            php-pear \
            php-pecl-redis \
            mod_php \
    && debuginfo-install --enablerepo=remi-php${PHP_MAJOR}${PHP_MINOR} -y php-fpm \
    && debuginfo-install -y httpd \
    && yum clean all \
    && rm -rf /var/cache/yum

# Installing vegeta
RUN curl -L -o /tmp/vegeta.tar.gz https://github.com/tsenart/vegeta/releases/download/v12.8.4/vegeta_12.8.4_linux_amd64.tar.gz \
    && tar -C /usr/bin -zxvf /tmp/vegeta.tar.gz vegeta \
    && rm /tmp/vegeta.tar.gz

# Installing composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Create coredumps folder
# If not generated, see: https://fromdual.com/hunting-the-core
RUN mkdir -p /tmp/corefiles
RUN chmod -R a+w /tmp/corefiles
ADD enable-coredump.sh /scripts/enable-coredump.sh

# Preparing PHP
RUN echo "date.timezone = UTC" > "/etc/php.d/00-adjust-timezones.ini"

# Add the wait script to the image: note SHA 672a28f0509433e3b4b9bcd4d9cd7668cea7e31a has been reviewed and should not
# be changed without an appropriate code review.
ADD https://raw.githubusercontent.com/eficode/wait-for/672a28f0509433e3b4b9bcd4d9cd7668cea7e31a/wait-for /scripts/wait-for.sh
RUN chmod +x /scripts/wait-for.sh

# Preparing PHP-FPM
RUN mkdir -p /run/php-fpm
RUN sed -i 's/^listen = .*$/listen = 9000/g' /etc/php-fpm.d/www.conf

# Preparing NGINX
RUN groupadd www-data
RUN adduser -M --system -g www-data www-data
ADD nginx.conf /etc/nginx/nginx.conf
ADD nginx.site.conf /etc/nginx/conf.d/default.conf

# Preparing HTTPD
RUN sed -i 's/Listen 80/Listen 81/' /etc/httpd/conf/httpd.conf
RUN echo "CoreDumpDirectory /tmp/corefiles" >> /etc/httpd/conf/httpd.conf

ADD run.sh /scripts/run.sh
ADD prepare.sh /scripts/prepare.sh

WORKDIR /var/www/html

ENV COMPOSER_CACHE_DIR /composer-cache
RUN mkdir -p ${COMPOSER_CACHE_DIR}
ENV COMPOSER_VENDOR_DIR /composer-vendor
RUN mkdir -p ${COMPOSER_VENDOR_DIR}

CMD [ "bash", "/scripts/run.sh" ]
