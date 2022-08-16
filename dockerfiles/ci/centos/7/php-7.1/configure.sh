if [ -z "${PHP_SRC_DIR}" ]; then
    echo "Please set PHP_SRC_DIR"
    exit 1
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
    --enable-zip \
    --with-apxs2 \
    --with-bz2 \
    --with-curl \
    --with-fpm-user=www-data \
    --with-fpm-group=www-data \
    --with-libedit \
    --with-mhash \
    --with-mysqli=mysqlnd \
    --with-gettext \
    --with-openssl \
    --with-pdo-mysql=mysqlnd \
    --with-pdo-pgsql \
    --with-pdo-sqlite \
    --with-pear \
    --with-readline \
    --with-xsl \
    --with-zlib \
    $@
