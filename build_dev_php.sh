#!/bin/bash

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
  elif [[ $version_id -eq 80100 && $version =~ alpha|beta|RC ]]; then
    download_url="https://downloads.php.net/~ramsey/php-${version}.tar.gz"
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

function build_php {
  local readonly version=$1 variants_s=$2
  local readonly version_id=$(php_version_id $version)
  local readonly download_dir="$PHP_HOMEDIR/sources/$version" \
    build_dir="$PHP_HOMEDIR/build-$version-$variants_s"
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
    options+=(
    --with-openssl=shared
    --with-zlib=shared
    --enable-dom=shared
    --enable-fileinfo=shared
    --enable-filter=shared
    --with-gmp=shared
    --enable-intl=shared
    --enable-mbstring=shared
    --enable-opcache=shared
    --enable-pdo=shared
    --with-pdo-pgsql=shared
    --enable-phar=shared
    --enable-simplexml=shared
    --enable-xmlreader=shared
    --with-iconv=shared
    $([[ $version_id -ge 80000 ]] && echo --with-zip=shared || echo --enable-zip=shared)
    --enable-ctype=shared
    --enable-session=shared
    --enable-tokenizer=shared
    --with-pgsql=shared
    --with-pdo-sqlite=shared,/usr
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
  if [[ $version_id -lt 70400 ]]; then
    options+=(--enable-hash=shared --enable-libxml=shared)
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
  if [[ $version_id -lt 70000 && $version_id -ge 50600 && ! -f .patch_conf_applied ]]; then
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

  "$download_dir/configure" "${options[@]}"
  make -j
  make install-sapi || true
  make install-binaries install-headers install-modules install-programs install-build

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

download_php "$1"
build_php "$1" "${2:-}"
