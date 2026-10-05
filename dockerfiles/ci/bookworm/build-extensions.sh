#!/bin/bash
set -eux

# We can't match for "shared" only, as zend_test is built shared. Match something explicitly.
SHARED_BUILD=$(if php -i | grep -q enable-pcntl=shared; then echo 1; else echo 0; fi)
PHP_VERSION_ID=$(php -r 'echo PHP_MAJOR_VERSION . PHP_MINOR_VERSION;')
PHP_ZTS=$(php -r 'echo PHP_ZTS;')
EXTENSION_DIR=$(php-config --extension-dir)

if [[ -z "${MAKE_JOBS:-}" || "${MAKE_JOBS}" == "0" ]]; then
  MAKE_JOBS="$(nproc)"
fi

export MAKEFLAGS="-j$MAKE_JOBS"

XDEBUG_VERSIONS=(-3.1.2)
if [[ $PHP_VERSION_ID -le 70 ]]; then
  XDEBUG_VERSIONS=(-2.7.2)
elif [[ $PHP_VERSION_ID -le 74 ]]; then
  XDEBUG_VERSIONS=(-2.9.2 -2.9.5)
elif [[ $PHP_VERSION_ID -le 80 ]]; then
  XDEBUG_VERSIONS=(-3.0.0)
elif [[ $PHP_VERSION_ID -le 81 ]]; then
  XDEBUG_VERSIONS=(-3.1.0)
elif [[ $PHP_VERSION_ID -le 82 ]]; then
  XDEBUG_VERSIONS=(-3.2.2)
elif [[ $PHP_VERSION_ID -le 83 ]]; then
  XDEBUG_VERSIONS=(-3.3.2)
else
  XDEBUG_VERSIONS=(-3.4.0)
fi

MONGODB_VERSION=
if [[ $PHP_VERSION_ID -le 70 ]]; then
  MONGODB_VERSION=-1.9.2
elif [[ $PHP_VERSION_ID -le 71 ]]; then
  MONGODB_VERSION=-1.11.1
elif [[ $PHP_VERSION_ID -le 73 ]]; then
  MONGODB_VERSION=-1.16.2
elif [[ $PHP_VERSION_ID -le 80 ]]; then
  MONGODB_VERSION=-1.20.1
elif [[ $PHP_VERSION_ID -ge 86 ]]; then
  MONGODB_VERSION=-2.5.3
fi

AMQP_VERSION=
if [[ $PHP_VERSION_ID -le 73 ]]; then
  AMQP_VERSION=-1.11.0
else
  AMQP_VERSION=-2.1.2
fi

AST_VERSION=
if [[ $PHP_VERSION_ID -le 71 ]]; then
  AST_VERSION=-1.0.16
fi

MEMCACHE_VERSION=
if [[ $PHP_VERSION_ID -le 74 ]]; then
  MEMCACHE_VERSION=-4.0.5.2
fi

SQLSRV_VERSION=
if [[ $PHP_VERSION_ID -le 70 ]]; then
  SQLSRV_VERSION=-5.3.0
elif [[ $PHP_VERSION_ID -le 71 ]]; then
  SQLSRV_VERSION=-5.6.1
elif [[ $PHP_VERSION_ID -le 74 ]]; then
  SQLSRV_VERSION=-5.8.0
elif [[ $PHP_VERSION_ID -le 80 ]]; then
  SQLSRV_VERSION=-5.11.0
elif [[ $PHP_VERSION_ID -le 82 ]]; then
  SQLSRV_VERSION=-5.12.0
fi

HOST_ARCH=$(if [[ $(file $(readlink -f $(which php))) == *aarch64* ]]; then echo "aarch64"; else echo "x86_64"; fi)

export PKG_CONFIG=/usr/bin/$HOST_ARCH-linux-gnu-pkg-config
# export CC=$HOST_ARCH-linux-gnu-gcc
# export CXX=$HOST_ARCH-linux-gnu-g++

iniDir=$(php -i | awk -F"=> " '/Scan this dir for additional .ini files/ {print $2}');

if [[ $SHARED_BUILD -ne 0 ]]; then
  # curl defines ldap_connect itself and it'll conflict otherwise with newer ldap versions
  sudo sed -i 's/ldap_connect/__ldap_connect/' /usr/include/ldap.h

  # Build curl versions
  CURL_VERSIONS="7.72.0 7.77.0"
  for curlVer in ${CURL_VERSIONS}; do
    echo "Build curl ${curlVer}..."
    cd /tmp
    curl -L -o curl.tar.gz https://curl.se/download/curl-${curlVer}.tar.gz
    tar -xf curl.tar.gz && rm curl.tar.gz
    cd curl-${curlVer}
    ./configure --with-openssl --prefix=/opt/curl/${curlVer}
    make -j "$MAKE_JOBS"
    make install
  done

  # Build core extensions as shared libraries.
  # We intentionally do not run 'make install' here so that we can test the
  # scenario where headers are not installed for the shared library.
  # ext/curl
  cd ${PHP_SRC_DIR}/ext/curl
  phpize
  ./configure
  make -j "$MAKE_JOBS"
  mv ./modules/*.so $EXTENSION_DIR
  make clean

  for curlVer in ${CURL_VERSIONS}; do
    PKG_CONFIG_PATH=/opt/curl/${curlVer}/lib/pkgconfig/
    ./configure
    make -j "$MAKE_JOBS"
    mv ./modules/curl.so $EXTENSION_DIR/curl-${curlVer}.so
    make clean
  done
  phpize --clean

  # ext/pdo
  cd ${PHP_SRC_DIR}/ext/pdo
  phpize
  ./configure
  make -j "$MAKE_JOBS"
  mv ./modules/*.so $(php-config --extension-dir)
  make clean;
  phpize --clean

  # TODO Add ext/pdo_mysql, ext/pdo_pgsql, and ext/pdo_sqlite
else
  pecl channel-update pecl.php.net;
  if [[ $PHP_VERSION_ID -ge 86 ]]; then
    # No PHP 8.6 compatible apcu / ast release on PECL yet: build pinned git commits.
    pushd /tmp
    git clone https://github.com/krakjoe/apcu.git
    cd apcu
    git checkout ebbcd3d153df21eaee3395413de515a30b48ef05
    phpize
    ./configure
    make -j"$MAKE_JOBS"
    make install
    cd ..
    git clone https://github.com/nikic/php-ast.git
    cd php-ast
    git checkout 64ea7276bcd9cf8e503b719aafbec4d802c9eacc
    phpize
    ./configure
    make -j"$MAKE_JOBS"
    make install
    popd
    echo "extension=apcu.so" >> ${iniDir}/apcu.ini;
    echo "extension=ast.so" >> ${iniDir}/ast.ini;
  else
    yes '' | pecl install apcu; echo "extension=apcu.so" >> ${iniDir}/apcu.ini;
    pecl install ast$AST_VERSION; echo "extension=ast.so" >> ${iniDir}/ast.ini;
  fi
  if [[ $PHP_VERSION_ID -ge 71 && $PHP_VERSION_ID -le 80 ]]; then
    yes '' | CFLAGS="-Wno-incompatible-function-pointer-types" pecl install mcrypt$(if [[ $PHP_VERSION_ID -le 71 ]]; then echo -1.0.0; fi); echo "extension=mcrypt.so" >> ${iniDir}/mcrypt.ini;
  fi

  if [[ $PHP_VERSION_ID -lt 85 ]]; then
    pecl install amqp$AMQP_VERSION; echo "extension=amqp.so" >> "${iniDir}/amqp.ini"
    yes 'no' | pecl install memcached; echo "extension=memcached.so" >> ${iniDir}/memcached.ini;
    yes '' | pecl install memcache$MEMCACHE_VERSION; echo "extension=memcache.so" >> ${iniDir}/memcache.ini;
    pecl install mongodb$MONGODB_VERSION; echo "extension=mongodb.so" >> ${iniDir}/mongodb.ini;

    # Xdebug is disabled by default
    for VERSION in "${XDEBUG_VERSIONS[@]}"; do
      pecl install xdebug$VERSION;
      cd $(php-config --extension-dir);
      mv xdebug.so xdebug$VERSION.so;
    done
  else
    cd /tmp

    # memcached master version
    git clone https://github.com/php-memcached-dev/php-memcached.git
    cd php-memcached
    git checkout 0b52d3657140750fa0eddd20cdbfc6edc8fbdadd
    phpize
    ./configure
    make -j"$MAKE_JOBS"
    make install
    echo "extension=memcached.so" >> ${iniDir}/memcached.ini;
    cd ..

    # memcache master version
    git clone https://github.com/websupport-sk/pecl-memcache.git
    cd pecl-memcache
    git checkout ac8e8c521a18aae14c8f2859694536ead304ce97
    if [[ $PHP_VERSION_ID -ge 86 ]]; then
      # PHP 8.6 build fix (PR #120) and session handlers (PR #122), both unmerged upstream.
      git apply /home/circleci/memcache-php86.patch
    fi
    phpize
    ./configure
    make -j"$MAKE_JOBS"
    make install
    echo "extension=memcache.so" >> ${iniDir}/memcache.ini;
    cd ..

    pecl install mongodb$MONGODB_VERSION; echo "extension=mongodb.so" >> ${iniDir}/mongodb.ini;

    # Xdebug master version (disabled by default)
    git clone https://github.com/xdebug/xdebug.git
    cd xdebug
    git checkout 64007df3a0925808022fb87b6c6f06febf058ee2
    phpize
    ./configure
    make -j"$MAKE_JOBS"
    make install
    cd ..
  fi
  if [[ $PHP_VERSION_ID -ge 86 ]]; then
    # rdkafka 6.0.5 and sqlsrv 5.13.3 don't build on PHP 8.6 yet (XtOffsetOf, zval_dtor,
    # EMPTY_SWITCH_DEFAULT_CASE, INI_INT/INI_BOOL removed; php_stream_wrapper_log_error() changed).
    # Patch the pinned releases until upstream ships PHP 8.6 support.
    pushd /tmp
    pecl download rdkafka-6.0.5
    echo "0af6b665c963c8c7d1109cec738034378d9c8863cbf612c0bd3235e519a708f1  rdkafka-6.0.5.tgz" | sha256sum -c -
    tar xzf rdkafka-6.0.5.tgz
    cd rdkafka-6.0.5
    sed -i -e 's/XtOffsetOf/offsetof/g' -e 's/zval_dtor(/zval_ptr_dtor_nogc(/g' \
           -e 's/EMPTY_SWITCH_DEFAULT_CASE();/default: ZEND_UNREACHABLE(); break;/' *.c *.h
    # Fail if the sed missed a site (`! grep` would not trip set -e).
    if grep -n 'XtOffsetOf\|zval_dtor(\|EMPTY_SWITCH_DEFAULT_CASE' *.c *.h; then exit 1; fi
    phpize
    ./configure
    make -j"$MAKE_JOBS"
    make install
    cd ..
    pecl download sqlsrv-5.13.3
    echo "1c3092ca793bb67002ca022c412aacabb79a3297ee7005e3b7cc91b1e7166d22  sqlsrv-5.13.3.tgz" | sha256sum -c -
    tar xzf sqlsrv-5.13.3.tgz
    cd sqlsrv-5.13.3
    sed -i -e 's/INI_BOOL( *\([a-z_]*\) *)/((bool) zend_ini_long(\1, strlen(\1), 0))/' \
           -e 's/INI_INT( *\([a-z_]*\) *)/zend_ini_long(\1, strlen(\1), 0)/' init.cpp
    sed -i 's/php_stream_wrapper_log_error(wrapper, options, /php_stream_wrapper_log_error(wrapper, NULL, options, E_WARNING, false, ZEND_ENUM_StreamErrorCode_Generic, /' shared/core_stream.cpp
    if grep -n 'INI_INT(\|INI_BOOL(' init.cpp; then exit 1; fi
    grep -q 'php_stream_wrapper_log_error(wrapper, NULL, options, E_WARNING' shared/core_stream.cpp
    phpize
    ./configure
    make -j"$MAKE_JOBS"
    make install
    popd
    echo "extension=rdkafka.so" >> ${iniDir}/rdkafka.ini;
  else
    pecl install rdkafka; echo "extension=rdkafka.so" >> ${iniDir}/rdkafka.ini;
    pecl install sqlsrv$SQLSRV_VERSION;
  fi
  # Since PHP 8.5, opcache is always built in and there is no opcache.so to load.
  if [[ $PHP_VERSION_ID -lt 85 ]]; then
    echo "zend_extension=opcache.so" >> ${iniDir}/../php-apache2handler.ini;
  fi

  # ext-parallel needs PHP 8 ZTS
  if [[ $PHP_VERSION_ID -ge 80 && $PHP_ZTS -eq 1 ]]; then
    pecl install parallel$(if [[ $PHP_VERSION_ID -ge 86 ]]; then echo -1.2.15; fi);
    echo "extension=parallel" >> ${iniDir}/parallel.ini;
  fi

  # ext-swoole needs PHP 8
  if [[ $PHP_VERSION_ID -ge 80 && $PHP_VERSION_ID -lt 85 ]]; then
    pushd /tmp
    if [[ $PHP_VERSION_ID -ge 83 ]]; then
      pecl download swoole-6.0.0RC1;
      tar xzf swoole-6.0.0RC1.tgz
      cd swoole-6.0.0RC1
    else
      pecl download swoole-5.1.6;
      tar xzf swoole-5.1.6.tgz
      cd swoole-5.1.6
    fi
    phpize
    ./configure --host=$HOST_ARCH-linux-gnu
    make -j "$MAKE_JOBS"
    make install
    popd
  fi

  # ext-grpc is needed for google spanner
  if [[ $PHP_VERSION_ID -ge 80 && $PHP_VERSION_ID -lt 85 ]]; then
    pecl install grpc-1.78.0;
    # avoid installing it by default, it seems to stall some testsuites.
  fi

  # We don't install any redis.so to inis, but allow selection at runtime.
  if [[ $PHP_VERSION_ID -lt 80 ]]; then
    pecl install redis-3.1.6
    mv $EXTENSION_DIR/redis.so $EXTENSION_DIR/redis-3.1.6.so
    pecl install redis-4.3.0
    mv $EXTENSION_DIR/redis.so $EXTENSION_DIR/redis-4.3.0.so
  fi
  if [[ $PHP_VERSION_ID -le 83 ]]; then
    pecl install redis-5.3.7
    # Redis 6.0.0 dropped support for PHP 7.1 and below
    if [[ $PHP_VERSION_ID -gt 71 ]]; then
        mv $EXTENSION_DIR/redis.so $EXTENSION_DIR/redis-5.3.7.so
        pecl install redis-6.0.2
    else
        ln -s $EXTENSION_DIR/redis.so $EXTENSION_DIR/redis-5.3.7.so
    fi
  fi
  if [[ $PHP_VERSION_ID -ge 84 ]]; then
    if [[ $PHP_VERSION_ID -ge 85 ]]; then
      git clone https://github.com/phpredis/phpredis.git
      cd phpredis
      git checkout 146ec813ea7ca85c9e3a7cff2caf977096e16acf
      phpize
      ./configure
      make -j"$MAKE_JOBS"
      make install
    else
      pecl install redis-6.1.0
    fi
  fi

fi
