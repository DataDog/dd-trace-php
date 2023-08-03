if [ -z "${PHP_SRC_DIR}" ]; then
    echo "Please set PHP_SRC_DIR"
    exit 1
fi

# needed for CentOS7 vs PHP 8.3 on amd64, otherwise you get a:
# ld: dynamic STT_GNU_IFUNC symbol `mb_wchar_to_utf16le' with pointer equality in `ext/mbstring/libmbfl/filters/mbfilter_utf16.o' can not be used when making an executable; recompile with -fPIE and relink with -pie
# see also https://github.com/php/php-src/issues/11603
if [ "$(uname -m)" = "x86_64" ]; then
    export LDFLAGS=-pie
    export CFLAGS=-fPIE
fi

${PHP_SRC_DIR}/configure \
    --enable-option-checking=fatal \
    --enable-calendar \
    --enable-cgi \
    --enable-exif \
    --enable-fpm \
    --enable-ftp \
    --enable-mbstring \
    --enable-mysqlnd \
    --enable-opcache \
    --enable-phpdbg \
    --enable-pcntl \
    --enable-shmop \
    --enable-sockets \
    --enable-sysvmsg \
    --enable-sysvsem \
    --enable-sysvshm \
    --with-apxs2 \
    --with-bz2 \
    --with-curl \
    --with-ffi \
    --with-fpm-user=www-data \
    --with-fpm-group=www-data \
    --with-libedit \
    --with-mhash \
    --with-mysqli=mysqlnd \
    --with-gettext \
    --with-openssl \
    --with-pdo-mysql=mysqlnd \
    --with-pear \
    --with-readline \
    --with-sodium \
    --with-xsl \
    --with-zip \
    --with-zlib \
    $@
