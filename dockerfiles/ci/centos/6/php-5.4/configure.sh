if [ -z "${PHP_SRC_DIR}" ]; then
    echo "Please set PHP_SRC_DIR"
    exit 1
fi

${PHP_SRC_DIR}/configure \
    --enable-option-checking=fatal \
    --enable-cgi \
    --enable-embed \
    --enable-fpm \
    --enable-ftp \
    --enable-mbstring \
    --enable-mysqlnd \
    --enable-pdo \
    --enable-sockets \
    --enable-zip \
    --with-curl \
    --with-fpm-user=www-data \
    --with-fpm-group=www-data \
    --with-libedit \
    --with-mhash \
    --with-mysqli=mysqlnd \
    --with-openssl \
    --with-pdo-mysql=mysqlnd \
    --with-pdo-pgsql \
    --with-pdo-sqlite \
    --with-pear \
    --with-readline \
    --with-zlib \
    $@
