if [ -z "${PHP_SRC_DIR}" ]; then
    echo "Please set PHP_SRC_DIR"
    exit 1
fi

${PHP_SRC_DIR}/configure \
    --disable-all \
    --enable-cgi \
    --enable-fpm \
    --enable-pcntl=shared \
    --enable-phpdbg \
    --enable-option-checking=fatal \
    --with-ffi=shared \
    --with-fpm-user=www-data \
    --with-fpm-group=www-data \
    --without-pear \
    $@
