#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$(readlink -f "$0")" )"
export REPO_ROOT="$PWD"
: "${PHP_HOMEDIR:=$(echo ~/php)}"

# If you want to customize installation directory, copy/hack this script.
mkdir -p "$PHP_HOMEDIR"
cd "$PHP_HOMEDIR"

# See https://php.net/downloads.php to know version numbers of recent PHP releases.

function php_version_id {
  local readonly version=$(echo $1 | sed 's/\(beta\|alpha\|RC\).\+//')
  local readonly major=$(echo $version | cut -d. -f1) \
    minor=$(echo $version | cut -d. -f2) \
    patch=$(echo $version | cut -d. -f3)

  patch=${patch:=0}

  echo $(($major * 10000 + $minor * 100 + $patch))
}

function download_php {
  local readonly version=$1
  local readonly download_dir="$PHP_HOMEDIR/sources/$version"
  local readonly version_id=$(php_version_id $version)

  mkdir -p "$download_dir"
  if [[ -f $download_dir/.download_success ]]; then
    return
  fi

  local download_url
  if [[ $version_id -lt 50400 ]]; then
    download_url="http://museum.php.net/php5/php-${version}.tar.gz"
  else
    download_url="https://www.php.net/distributions/php-${version}.tar.gz"
  fi

  cd "$download_dir"
  curl -L -f -v "$download_url" | tar --strip-components=1 -xzf -

  touch "$download_dir"/.download_success
}

function contains_element {
  local el match="$1"
  shift
  for el; do [[ "$el" == "$match" ]] && return 0; done
  return 1
}

function has_apxs {
  if [[ -v APXS ]]; then
    return 0
  fi
  if which apxs > /dev/null; then
    return 0;
  fi
  return 1;
}

function prepare_apxs {
  cat > /tmp/apxs_wrapper <<EOD
#!/bin/bash -e

APXS=\${APXS:-\$(which apxs)}

if [[ \$1 == -q && \$2 == LIBEXECDIR ]]; then
  echo "$prefix_dir/lib"
else
  "\$APXS" "\$@"
fi
EOD
chmod +x /tmp/apxs_wrapper
}

function get_xdebug_version {
  local -r version=$1
  local readonly version_id=$(php_version_id $version)
  if [[ $version_id -lt 70300 ]]; then
    echo '2.8.1'
  elif [[ $version_id -lt 80000 ]]; then
    echo '2.9.8'
  elif [[ $version_id -ge 80300 ]]; then
    echo '3.3.1'
  else
    echo '3.2.2'
  fi
}

function build_php {
  local readonly version=$1 variants_s=$2 prefix_dir_var=$3
  local readonly version_id=$(php_version_id $version)
  local readonly download_dir="$PHP_HOMEDIR/sources/$version" \
    build_dir="$PHP_HOMEDIR/build-$version-$variants_s"
  local cflags="${CFLAGS:-} -ggdb" cxxflags="${CXXFLAGS:-} -ggdb" \
    ldflags="${LDFLAGS:-}" cppflags="${CPPFLAGS:-}"
  local -a variants
  readarray -td- variants <<< "$variants_s-"
  unset 'variants[-1]'
  declare -p variants

  local prefix_dir
  if [[ -z "${variants[0]:-}" ]]; then
    prefix_dir="$PHP_HOMEDIR/$version"
  else
    prefix_dir="$PHP_HOMEDIR/$version-$variants_s"
  fi

  declare -g "$prefix_dir_var=$prefix_dir"

  local minimal=0
  if contains_element minimal "${variants[@]}"; then
    minimal=1
  fi

  local -a options
  options=(
    --prefix="$prefix_dir"
    --disable-all
    --enable-cli
    $([[ $version_id -ge 50400 ]] && echo --enable-fpm || echo --disable--fpm)
    --with-fpm-user=$USER
    --enable-sockets=shared
    --enable-posix=shared
    --enable-pcntl=shared
  )

  if [[ $minimal -eq 0 ]]; then
    if [[ -d /opt/homebrew/opt/libiconv ]]; then
      options+=(--with-iconv=shared,/opt/homebrew/opt/libiconv)
    else
      options+=(--with-iconv=shared)
    fi

    if [[ -d /opt/homebrew/opt/zlib ]]; then
      options+=(--with-zlib-dir=/opt/homebrew/opt/zlib)
    fi

    if [[ -f /opt/homebrew/include/gmp.h ]]; then
      options+=(--with-gmp=shared,/opt/homebrew)
    else
      options+=(--with-gmp=shared)
    fi

    if [[ -d /opt/homebrew/opt/sqlite ]]; then
      options+=(--with-pdo-sqlite=shared,/opt/homebrew/opt/sqlite)
    else
      options+=(--with-pdo-sqlite=shared,/usr)
    fi

    set -x
    local -r libpq_dir=/opt/homebrew/opt/libpq
    if [[ -n $libpq_dir ]]; then
      export LDFLAGS="${LDFLAGS:-} -L$libpq_dir/lib"
      export CPPFLAGS="${CPPFLAGS:-} -I$libpq_dir/include"
      export PATH="$libpq_dir/bin:$PATH"
      options+=(
      "--with-pgsql=shared,$libpq_dir/bin"
      "--with-pdo-pgsql=shared,$libpq_dir/bin")
    else
      options+=(
      --with-pgsql=shared
      --with-pdo-pgsql=shared)
    fi


    options+=(
    --enable-gd=shared
    --with-openssl=shared
    --with-zlib=shared
    --enable-dom=shared
    --enable-fileinfo=shared
    --enable-filter
    --enable-intl=shared
    --enable-mbstring=shared
    --enable-opcache=shared
    "--enable-pdo=$([[ $(uname -o) != Darwin ]] && echo shared)"
    --enable-phar=shared
    --enable-xml
    --enable-simplexml=shared
    --enable-xmlreader=shared
    --enable-xmlwriter=shared
    $([[ $version_id -ge 70400 ]] && echo --with-zip=shared || echo --enable-zip=shared)
    --enable-ctype=shared
    --enable-session=shared
    --enable-tokenizer=shared
    --with-curl=shared)
  fi

  if has_apxs; then
    if [[ ${NO_APX_WRAPPER-} -eq 1 ]]; then
      options+=(--with-apxs2=$(which apxs2))
    else
      prepare_apxs
      options+=(--with-apxs2=/tmp/apxs_wrapper)
    fi
  fi

  if [[ $version_id -lt 80000 ]]; then
    options+=(--enable-json) # not shared to make it consistent with php 8
  fi

  if [[ $minimal -eq 0 ]]; then
    local mysql_config= mysql_prefix=
    if contains_element nomysqlnd "${variants[@]}"; then
      echo "You may need to symlink libmysql client:"
      echo "> ln -s /usr/lib/x86_64-linux-gnu/libmysqlclient.a /usr/lib/x86_64-linux-gnu/libmysqlclient_r.a"
      echo "> ln -s /usr/lib/x86_64-linux-gnu/libmysqlclient.so /usr/lib/x86_64-linux-gnu/libmysqlclient_r.so"
      mysql_config=/usr/bin/mysql_config
      mysql_prefix=/usr
    else
      mysql_config=mysqlnd
      mysql_prefix=mysqlnd
      options+=(--enable-mysqlnd=shared)
    fi

    if [[ $version_id -lt 70000 ]]; then
      options+=(--with-mysql=shared,$mysql_prefix)
    fi

    options+=(
    --with-mysqli=shared,$mysql_config
    --with-pdo-mysql=shared,$mysql_prefix
    )
  fi # minimal

  if ! contains_element release "${variants[@]}"; then
    options+=(--enable-debug)
  fi
  if contains_element zts "${variants[@]}"; then
    if [[ $version_id -ge 80000 ]]; then
      options+=(--enable-zts)
    else
      options+=(--enable-maintainer-zts)
    fi
  fi
  if contains_element asan "${variants[@]}"; then
    cppflags="$cppflags -DZEND_TRACK_ARENA_ALLOC"
    cflags="$cflags -fsanitize=address"
    cxxflags="$cxxflags -fsanitize=address"
    ldflags="$ldflags -fsanitize=address"
  fi
  if [[ $version_id -lt 70400 ]]; then
    options+=(--enable-hash --enable-libxml=shared)
  else
    # 7.4+
    options+=(--with-libxml=shared)
  fi

  echo "Build options: ${options[@]}"

  cd "$download_dir"
  if [[ $version_id -lt 50400 && ! -f .patch_applied ]]; then
    patch -p0 < "$REPO_ROOT"/php_patches/newish_libxml.patch
    touch .patch_applied
  fi
  if [[ $version_id -lt 70000 && $version_id -ge 50500 && ! -f .patch_applied ]]; then
    patch -p0 < "$REPO_ROOT"/php_patches/opcache_num_var.patch
    touch .patch_applied
  fi
  if [[ $version_id -lt 70016 && $version_id -ge 50600 && ! -f .patch_conf_applied ]]; then
    patch -p0 < "$REPO_ROOT"/php_patches/configure_gmp.patch
    touch .patch_conf_applied
  fi
  if [[ $version_id -lt 70100 && $version_id -ge 70000 && ! -f .patch_conf_icu ]]; then
    patch -p0 < "$REPO_ROOT"/php_patches/configure_icu.patch
    touch .patch_conf_icu
  fi
  if [[ $version_id -lt 70200 && ! -f .patch_conf_curl ]]; then
    patch -r - -p0 < "$REPO_ROOT"/php_patches/configure_curl.patch || \
      patch -p0 < "$REPO_ROOT"/php_patches/configure_curl_old.patch
    touch .patch_conf_curl
  fi
  if [[ $version_id -lt 70300 && $version_id -ge 70000 && ! -f .patch_ns_icu ]]; then
    patch -p1 < "$REPO_ROOT"/php_patches/recent_icu.patch
    touch .patch_ns_icu
  fi

  rm -rf "$build_dir"
  mkdir -p "$build_dir"
  cd "$build_dir"

  CFLAGS="$cflags" CXXFLAGS="$cxxflags" CPPFLAGS="$cppflags" \
    LDFLAGS="$ldflags" \
    "$download_dir/configure" "${options[@]}"
  make -j $(nproc)
  make install-sapi || true
  make install-binaries install-headers install-modules install-programs install-build

  rm -rf "$build_dir"
  cd -
}

function download_and_extract_openssl {
  local -r openssl_version=$1 major=$2
  local url=
  if [[ $major != 1.0.2 ]]; then
    url="https://www.openssl.org/source/openssl-${openssl_version}.tar.gz"
  else
    url="https://www.openssl.org/source/old/$major/openssl-${openssl_version}.tar.gz"
  fi

  local openssl_source_dir="$HOME/php/sources/openssl-${openssl_version}"

  if [[ ! -f $openssl_source_dir/.extracted ]]; then
    mkdir -p "$openssl_source_dir"
    curl -Lf "$url" -o - | tar -xz -C "$openssl_source_dir" --strip-components=1
    touch "$openssl_source_dir/.extracted"
  fi
  echo "$openssl_source_dir"
}

function install_openssl {
  local -r version=$1 major=$(echo $1 | cut -d. -f 1-2)
  local -r install_dir="$HOME/php/openssl$major"
  if [[ -f $install_dir/.installed ]]; then
    return
  fi

  local -r source_dir="$(download_and_extract_openssl $version $major)"
  local -r build_dir="$HOME/php/build/openssl$major"

  mkdir -p "$build_dir"
  pushd "$build_dir"
  if [[ $major = 1.0 ]]; then
    cp -a "$source_dir/." "$build_dir"
  fi
  if [[ $version = '1.0.2u' && $(uname -o) = 'Darwin' && $(arch) = 'arm64' ]]; then
    curl -Lf https://gist.githubusercontent.com/felixbuenemann/5f4dcb30ebb3b86e1302e2ec305bac89/raw/b339a33ff072c9747df21e2558c36634dd62c195/openssl-1.0.2u-darwin-arm64.patch | patch -p1
    "./Configure" shared zlib no-tests --prefix="$install_dir" \
      --openssldir="$install_dir" darwin64-arm64-cc
    make depend
  else
    "$source_dir/config" --prefix="$install_dir" --openssldir="$install_dir" shared zlib no-tests
  fi

  make -j && make install_sw
  touch "$install_dir/.installed"
  popd
  rm -rf "$build_dir"

  echo "Installed OpenSSL $version in $install_dir"
}
function openssl_pkg_config {
  local -r php_version=$1
  local -r php_version_id=$(php_version_id $php_version)
  if [[ $php_version_id -lt 70100 ]]; then
    echo "$HOME/php/openssl1.0/lib/pkgconfig"
  else
    echo "$HOME/php/openssl1.1/lib/pkgconfig"
  fi
}

function download_and_extract_icu {
  local -r icu_version="60.3"
  local -r url="https://github.com/unicode-org/icu/releases/download/release-${icu_version//./-}/icu4c-${icu_version//./_}-src.tgz"
  local icu_source_dir="$HOME/php/sources/icu-${icu_version}"

  if [[ ! -f $icu_source_dir/.extracted ]]; then
    mkdir -p "$icu_source_dir"
    curl -Lf "$url" -o - | tar -xz -C "$icu_source_dir" --strip-components=1
    touch "$icu_source_dir/.extracted"
  fi
  echo "$icu_source_dir"
}

# Function to install ICU
function install_icu {
  local -r install_dir="$HOME/php/icu-60"
  if [[ -f $install_dir/.installed ]]; then
    return
  fi

  local -r source_dir="$(download_and_extract_icu)"
  local -r build_dir="$HOME/php/build/icu-60"

  mkdir -p "$build_dir"
  cd "$build_dir"
  "$source_dir/source/configure" --prefix="$install_dir"

  make -j && make install
  touch "$install_dir/.installed"
  cd -
  rm -rf "$build_dir"

  echo "Installed ICU in $install_dir"
}

function download_and_extract_libxml2 {
  local -r libxml2_version="2.12.4"
  local -r url="https://download.gnome.org/sources/libxml2/2.12/libxml2-${libxml2_version}.tar.xz"
  local libxml2_source_dir="$HOME/php/sources/libxml2-${libxml2_version}"

  if [[ ! -f $libxml2_source_dir/.extracted ]]; then
    mkdir -p "$libxml2_source_dir"
    curl -Lf "$url" -o - | tar -xJ -C "$libxml2_source_dir" --strip-components=1
    touch "$libxml2_source_dir/.extracted"
  fi
  echo "$libxml2_source_dir"
}

function install_libxml2 {
  local -r install_dir="$HOME/php/libxml2"
  if [[ -f $install_dir/.installed ]]; then
    return
  fi

  local -r source_dir="$(download_and_extract_libxml2)"
  local -r build_dir="$HOME/php/build/libxml2"

  mkdir -p "$build_dir"
  cd "$build_dir"
  CXXFLAGS="-g -ggdb -O0" "$source_dir/configure" --prefix="$install_dir"

  make -j && make install
  touch "$install_dir/.installed"
  cd -
  rm -rf "$build_dir"

  echo "Installed libxml2 in $install_dir"
}

function download_and_extract_xdebug {
  local -r xdebug_version="$1"
  local url
  local -r xdebug_source_dir="$HOME/php/sources/xdebug-${xdebug_version}"

  if [[ -f $xdebug_source_dir/.extracted ]]; then
    return
  fi
  set -x

  mkdir -p "$xdebug_source_dir"
  if [[ $xdebug_version == *.* ]]; then
    url=https://github.com/xdebug/xdebug/archive/refs/tags/${xdebug_version}.tar.gz
    curl -Lf "$url" -o - | tar -xz -C "$xdebug_source_dir" --strip-components=1
  else
    git clone -b master https://github.com/xdebug/xdebug.git "$xdebug_source_dir"
    git -C "$xdebug_source_dir" checkout "$xdebug_version"
  fi

  touch "$xdebug_source_dir/.extracted"
}
function install_xdebug {
  local -r xdebug_version=$1 php_prefix=$2
  local -r xdebug_source_dir="$HOME/php/sources/xdebug-${xdebug_version}"
  local -r build_dir="$HOME/php/build/xdebug-${xdebug_version}"
  local -r xdebug_so=$(find "$php_prefix/lib" -name xdebug.so)

  if [[ -n $xdebug_so ]]; then
    return
  fi

  cd "$xdebug_source_dir"
  "$php_prefix/bin/phpize"
  mkdir -p "$build_dir"
  cd "$build_dir"
  "$xdebug_source_dir/configure" "--with-php-config=$php_prefix/bin/php-config"
  make -j
  make install
  cd -

  rm -rf "$build_dir"
}

if [[ "$#" == 0 ]]
then
    echo Usage: $0 '<php version>' '[variant1-variant2-...]'
    echo
    echo "$0 7.2.17 zts-release"
    echo
    echo "Available variants: zts, release, nomysqlnd, minimal"
    echo "CC, CFLAGS, etc. are picked up"
    exit 0
fi

if [[ -d /opt/homebrew/lib ]]; then
  export LDFLAGS="${LDFLAGS:-} -L/opt/homebrew/lib"
  export CPPFLAGS="${CPPFLAGS:-} -I/opt/homebrew/include"
fi
export CXXFLAGS="${CXXFLAGS:-} -std=c++11"
export CFLAGS="${CFLAGS:-} -Wno-implicit-function-declaration"

install_openssl 1.0.2u
install_openssl 1.1.1w
install_icu
install_libxml2

if [[ $1 == deps ]]; then
  exit 0
fi

download_php "$1"

export PKG_CONFIG_PATH=$(openssl_pkg_config "$1"):$HOME/php/icu-60/lib/pkgconfig:$HOME/php/libxml2/lib/pkgconfig
PATH=$HOME/php/icu-60/bin:$PATH
build_php "$1" "${2:-}" PREFIX

xdebug_version=$(get_xdebug_version "$1")
download_and_extract_xdebug "$xdebug_version"
install_xdebug "$xdebug_version" "$PREFIX"
