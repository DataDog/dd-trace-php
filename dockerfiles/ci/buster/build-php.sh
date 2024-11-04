#!/bin/bash
set -eux

TARGETPLATFORM=$1
BASE_INSTALL_DIR=$2
INSTALL_VERSION=$3
PHP_VERSION=$4
SHARED_BUILD=${5:-0}

PHP_VERSION_ID=${PHP_VERSION:0:3}
PHP_VERSION_ID=${PHP_VERSION_ID/./}
INSTALL_DIR=$BASE_INSTALL_DIR/$INSTALL_VERSION

if [[ ${INSTALL_VERSION} == *asan* ]]; then
  export CFLAGS='-fsanitize=address -DZEND_TRACK_ARENA_ALLOC'
  # GCC has `-shared-libasan` default, clang has '-static-libasan' default
  export LDFLAGS='-fsanitize=address -shared-libasan'
fi

if [[ ${PHP_VERSION_ID} -le 73 ]]; then
  if [[ -z "${CFLAGS:-}" ]]; then
    export CFLAGS="-Wno-implicit-function-declaration -DHAVE_POSIX_READDIR_R=1 -DHAVE_OLD_READDIR_R=0"
  else
    export CFLAGS="${CFLAGS} -Wno-implicit-function-declaration -DHAVE_POSIX_READDIR_R=1 -DHAVE_OLD_READDIR_R=0"
  fi
fi

mkdir -p /tmp/build-php && cd /tmp/build-php

mkdir -p ${INSTALL_DIR}/conf.d

HOST_ARCH=$(if [[ $TARGETPLATFORM == "linux/arm64" ]]; then echo "aarch64"; else echo "x86_64"; fi)

PKG_CONFIG=/usr/bin/$HOST_ARCH-linux-gnu-pkg-config \
# CC=$HOST_ARCH-linux-gnu-gcc \
LIBS=-ldl \
${PHP_SRC_DIR}/configure \
    $(if [[ $SHARED_BUILD -ne 0 ]]; then echo \
        --disable-all \
        --enable-phpdbg \
        --enable-pcntl=shared \
        --enable-mbstring=shared \
        $(if [[ ${PHP_VERSION_ID} -ge 74 ]]; then echo --with-ffi=shared; fi) \
        $(if [[ ${PHP_VERSION_ID} -le 74 ]]; then echo --enable-json=shared; fi) \
        --without-pear \
    ; else echo \
        --disable-phpdbg \
        --enable-bcmath \
        --enable-ftp \
        $(if [[ ${PHP_VERSION_ID} -ge 71 ]]; then echo --enable-intl; fi) \
        --enable-mbstring \
        --enable-opcache \
        $(if [[ ${PHP_VERSION_ID} -ge 80 ]]; then echo --enable-zend-test=shared; fi) \
        --enable-pcntl \
        --enable-soap \
        --enable-sockets \
        $(if [[ ${PHP_VERSION_ID} -le 73 ]]; then echo --enable-zip; fi) \
        $(if [[ ${PHP_VERSION_ID} -ge 74 ]];
          then echo --enable-gd --with-jpeg --with-freetype --with-webp;
          else echo --with-gd --with-png-dir --with-jpeg-dir=/usr/include/;
        fi) \
        --with-curl \
        $(if [[ ${PHP_VERSION_ID} -ge 74 ]]; then echo --with-ffi; fi) \
        --with-libedit \
        $(if [[ ${PHP_VERSION_ID} -le 70 ]]; then echo --with-mcrypt; fi) \
        --with-mhash \
        --with-mysqli=mysqlnd \
        --with-openssl \
        --with-pdo-mysql=mysqlnd \
        --with-pdo-pgsql \
        --with-pdo-sqlite \
        --with-pear \
        --with-readline \
        $(if [[ ${PHP_VERSION_ID} -ge 72 ]]; then echo --with-sodium; fi) \
        --with-xsl \
        $(if [[ ${PHP_VERSION_ID} -ge 74 ]]; then echo --with-zip; fi) \
        --with-zlib \
    ; fi) \
    --enable-cgi \
    --enable-embed \
    --enable-fpm \
    --with-fpm-user=www-data \
    --with-fpm-group=www-data \
    --enable-option-checking=fatal \
    --program-prefix= \
    --host=$HOST_ARCH-linux-gnu \
    $(if [[ $INSTALL_VERSION == *debug* ]]; then echo --enable-debug; fi) \
    $(if [[ $INSTALL_VERSION == *zts* ]]; then echo --enable$(if grep -q 'maintainer-zts' ${PHP_SRC_DIR}/configure; then echo "-maintainer"; fi)-zts; fi) \
    `# https://externals.io/message/118859` \
    $(if [[ $INSTALL_VERSION == *zts* ]]; then echo --disable-zend-signals; fi) \
    $(if [[ $INSTALL_VERSION == *zts* && ${PHP_VERSION_ID} -ge 82 ]]; then echo --enable-zend-max-execution-timers; fi) \
    $(if [[ $INSTALL_VERSION == *asan* ]]; then echo --without-pcre-jit; fi) \
    --prefix=${INSTALL_DIR} \
    --with-config-file-path=${INSTALL_DIR} \
    --with-config-file-scan-dir=${INSTALL_DIR}/conf.d

make -j "$((`nproc`+1))" || true

if ! [[ -f ext/phar/phar.phar ]] && [[ ${INSTALL_VERSION} == *asan* ]]; then
  # Cross-compilation with asan and qemu will fail with a segfault instead. Handle this.
  sed -ir 's/TEST_PHP_EXECUTABLE_RES =.*/TEST_PHP_EXECUTABLE_RES = 1/' Makefile
  mkdir -p ext/phar/
  touch ext/phar/phar.phar
  # ensure compilation finishes, then back up php
  make || true;
  exit;
fi

make install

if [[ ${INSTALL_VERSION} != *asan* ]]; then
  # In two steps, because: You've configured multiple SAPIs to be built. You can build only one SAPI module plus CGI, CLI and FPM binaries at the same time.
  sed -i 's/--enable-embed/--with-apxs2=\/usr\/bin\/apxs2/' config.nice
  ./config.nice
  make -j "$((`nproc`+1))"
  cp .libs/libphp*.so ${INSTALL_DIR}/lib/apache2handler-libphp.so
fi

switch-php $INSTALL_VERSION;
