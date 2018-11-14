
FROM ubuntu:14.04

RUN apt-get update && apt-get upgrade -y \
    && apt-get -y install build-essential curl \
    libxml2-dev \
    re2c libbz2-dev \
    pkg-config libssl-dev \
    libcurl4-openssl-dev \
    libjpeg-dev \
    libpng-dev \
    libxpm-dev \
    libmysqlclient-dev \
    libpq-dev \
    libicu-dev \
    libfreetype6-dev \
    libldap2-dev \
    libxslt-dev \
    libldb-dev \
    libt1-dev \
    autoconf \
    && ln -s  x86_64-linux-gnu/curl /usr/include/curl \
    && curl -L 'http://launchpadlibrarian.net/140087283/libbison-dev_2.7.1.dfsg-1_amd64.deb' -o /tmp/libbison-dev.deb \
    && curl -L 'http://launchpadlibrarian.net/140087282/bison_2.7.1.dfsg-1_amd64.deb' -o /tmp/bison.deb \
    && dpkg -i /tmp/*.deb && rm /tmp/*.deb

RUN mkdir -p /src/php; (cd /src/php; curl -L https://github.com/php/php-src/archive/php-5.6.37.tar.gz | tar --strip-component=1 -zx )
WORKDIR /src/php

RUN ./buildconf --force

RUN apt-get install -y libgmp-dev libmcrypt-dev && ln -s /usr/include/x86_64-linux-gnu/gmp.h /usr/include/gmp.h
RUN apt-get install -y valgrind vim

RUN ./configure  \
    --enable-mbstring \
    --enable-zip \
    --enable-bcmath \
    --enable-pcntl \
    --enable-ftp \
    --enable-exif \
    --enable-calendar \
    --enable-sysvmsg \
    --enable-sysvsem \
    --enable-sysvshm \
    --enable-wddx \
    --with-mysqli --with-mysql --with-pdo-mysql --enable-opcache --enable-mysqlnd \
    --with-config-file-scan-dir=/usr/local/etc/php/conf.d \
    --with-config-file-path=/usr/local/etc/php \
    --with-curl \
    --with-mcrypt \
    --with-t1lib=/usr \
    --with-iconv \
    --with-gmp \
    --with-gd \
    --with-jpeg-dir=/usr \
    --with-webp-dir=/usr \
    --with-png-dir=/usr \
    --with-zlib-dir=/usr \
    --with-xpm-dir=/usr \
    --with-freetype-dir=/usr \
    --with-t1lib=/usr \
    --enable-gd-native-ttf \
    --enable-gd-jis-conv \
    --with-openssl \
    --with-zlib=/usr \
    --with-bz2=/usr \
    --with-kerberos=shared,/usr/lib \
    --disable-nls \
    --enable-debug

RUN make -j 2

RUN make install

RUN mkdir -p /usr/local/etc/php/conf.d

RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
  && php -r "if (hash_file('sha384', 'composer-setup.php') === '93b54496392c062774670ac18b134c3b3a95e5a5e5c8f1a9f115f203b75bf9a129d5daa8ba6a13e2cc8a1da0806388a8') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"\
  && php composer-setup.php && php -r "unlink('composer-setup.php');"\
  && mv composer.phar /usr/local/bin/composer

CMD ["bash"]

ENTRYPOINT ["/bin/bash", "-c"]
