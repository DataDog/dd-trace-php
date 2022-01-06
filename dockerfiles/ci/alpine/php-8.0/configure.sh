if [ -z "${PHP_SRC_DIR}" ]; then
    echo "Please set PHP_SRC_DIR"
    exit 1
fi

${PHP_SRC_DIR}/configure \
    --build="$(dpkg-architecture --query DEB_BUILD_GNU_TYPE)" \
    --enable-option-checking=fatal \
    --enable-cgi \
    --enable-embed \
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
    --with-pdo-pgsql \
    --with-pdo-sqlite \
    --with-pear \
    --with-readline \
    --with-sodium \
    --with-zip \
    --with-zlib \
    $@
