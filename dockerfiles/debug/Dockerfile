FROM ubuntu:16.04

ARG apache_version
ARG php_version

ENV DEBIAN_FRONTEND noninteractive
RUN ln -fs /usr/share/zoneinfo/America/New_York /etc/localtime

RUN apt-get update

RUN apt-get install -y \
    git \
    gdb \
    tzdata \
    gcc \
    unzip  \
    make  \
    build-essential \
    autoconf \
    automake \
    libtool \
    bison \
    re2c \
    libapr1-dev \
    libaprutil1-dev \
    libpcre3 \
    libpcre3-dev \
    zlib1g-dev \
    libxml2-dev  \
    vim

RUN dpkg-reconfigure --frontend noninteractive tzdata

ADD http://archive.apache.org/dist/httpd/httpd-${apache_version}.tar.gz /sources/httpd-${apache_version}.tar.gz
RUN tar xvzf /sources/httpd-${apache_version}.tar.gz -C /opt

WORKDIR /opt/httpd-${apache_version}

RUN CFLAGS='-g' ./configure --prefix=/usr/local/apache \
        --enable-so	\
        --enable-cgi	\
        --enable-info	\
        --enable-rewrite	\
        --enable-speling	\
        --enable-usertrack	\
        --enable-deflate
RUN make
RUN make install

# Building PHP
ADD https://github.com/php/php-src/archive/php-${php_version}.zip /sources/php-${php_version}.zip
WORKDIR /sources
RUN unzip php-${php_version}.zip -d /opt
WORKDIR /opt/php-src-php-${php_version}
RUN ./buildconf --force
RUN ./configure --enable-debug --without-pear --with-apxs2=/usr/local/apache/bin/apxs
RUN make -j4
RUN make install

RUN echo "add-auto-load-safe-path /opt/php-src-php-${php_version}/.gdbinit" >>  /root/.gdbinit

CMD bash
