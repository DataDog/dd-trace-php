if [ -z "${PHP_SRC_DIR}" ]; then
    echo "Please set PHP_SRC_DIR"
    exit 1
fi

${PHP_SRC_DIR}/configure \
    --enable-option-checking=fatal \
    --enable-cgi \
    --enable-fpm \
    --enable-ftp \
    --enable-mbstring \
    --enable-opcache \
    --enable-phpdbg \
    --enable-sockets \
    --with-curl \
    --with-ffi \
    --with-fpm-user=www-data \
    --with-fpm-group=www-data \
    --with-libedit \
    --with-mhash \
    --with-mysqli=mysqlnd \
    --with-openssl \
    --with-pdo-mysql=mysqlnd \
    --with-pear \
    --with-readline \
    --with-zip \
    --with-zlib \
    --without-pdo-sqlite \
    --without-sqlite3 \
    $@
