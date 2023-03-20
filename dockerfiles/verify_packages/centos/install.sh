#!/usr/bin/env sh

set -e

OS_VERSION=$(source /etc/os-release; echo $VERSION_ID)

function do_retry() {
  RETRIES=3
  while
    ! "$@"
  do
    if ! ((--RETRIES)); then
      return 1
    fi
  done
}

# Enable epel repo
do_retry rpm -Uvh https://dl.fedoraproject.org/pub/epel/epel-release-latest-${OS_VERSION}.noarch.rpm

# Installing pre-requisites
do_retry yum install -y wget nginx httpd

# Installing php
do_retry rpm -Uvh http://rpms.famillecollet.com/enterprise/remi-release-${OS_VERSION}.rpm
do_retry yum --enablerepo=remi-php${PHP_MAJOR}${PHP_MINOR} install -y \
    php-cli \
    php-fpm \
    php-opcache \
    php-pear \
    mod_php

# Preparing PHP-FPM
# Where php-fpm PID will be stored
mkdir -p /run/php-fpm
# For cases when it defaults to UDS
sed -i 's/^listen = .*$/listen = 9000/g' /etc/php-fpm.d/www.conf
# Passing envs to php-fpm process directly for simplicity. Note that on PHP 5.4 clear_env does not appear in www not
# even commented, so we remove potential existing line and add it at the end of the config file.
sed -i 's/^;*clear_env.*$//g' /etc/php-fpm.d/www.conf
echo 'clear_env = no' >> /etc/php-fpm.d/www.conf
# Enabling logs
sed -i 's/^;*catch_workers_output.*$/catch_workers_output = yes/g' /etc/php-fpm.d/www.conf

# Preparing NGINX
groupadd www-data
adduser -M --system -g www-data www-data
# Note: ignoring alias cp='cp -i' via leading `\` to avoid interactive mode for override operation.
\cp $(pwd)/dockerfiles/verify_packages/nginx.conf /etc/nginx/nginx.conf

# Installing dd-trace-php
INSTALL_TYPE="${INSTALL_TYPE:-php_installer}"
if [ "$INSTALL_TYPE" = "native_package" ]; then
    echo "Installing dd-trace-php using the OS-specific package installer"
    rpm -ivh $(pwd)/build/packages/*$(uname -m)*.rpm
else
    echo "Installing dd-trace-php using the new PHP installer"
    installable_bundle=$(find "$(pwd)/build/packages" -maxdepth 1 -name "dd-library-php-*-$(uname -m)-linux-gnu.tar.gz")
    php datadog-setup.php --file "$installable_bundle" --php-bin all
fi

# Starting services
php-fpm
sleep 0.5
nginx
sleep 0.5
httpd
sleep 0.5

echo "Installation completed successfully"
