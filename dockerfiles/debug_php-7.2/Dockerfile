
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
    libgmp-dev libmcrypt-dev \
    libwebp-dev \
    && ln -s  x86_64-linux-gnu/curl /usr/include/curl \
    && ln -s /usr/include/x86_64-linux-gnu/gmp.h /usr/include/gmp.h \
    && curl -L 'http://launchpadlibrarian.net/140087283/libbison-dev_2.7.1.dfsg-1_amd64.deb' -o /tmp/libbison-dev.deb \
    && curl -L 'http://launchpadlibrarian.net/140087282/bison_2.7.1.dfsg-1_amd64.deb' -o /tmp/bison.deb \
    && dpkg -i /tmp/*.deb && rm /tmp/*.deb \
    && mkdir -p /src/php; (cd /src/php; curl -L https://github.com/php/php-src/archive/php-7.2.9.tar.gz | tar --strip-component=1 -zx )

WORKDIR /src/php

RUN ./buildconf --force && ./configure \
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

RUN make -j 4 && make install

RUN apt-get install -y vim valgrind

CMD ["bash"]

ENTRYPOINT ["/bin/bash", "-c"]
