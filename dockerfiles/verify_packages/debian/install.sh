#!/usr/bin/env sh

set -e

# Common installations
apt update
apt install -y \
    apt-transport-https \
    lsb-release \
    ca-certificates \
    curl \
    software-properties-common \
    nginx \
    apache2 \
    procps \
    gnupg

# Installing php
if [ "${INSTALL_MODE}" = "native" ]; then
    apt-get install -y ${PHP_PACKAGE}
elif [ "${INSTALL_MODE}" = "sury" ]; then
    curl -sSL -o /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
    sh -c 'echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list'
    apt update
    apt install -y \
        php${PHP_VERSION}-cli \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-fpm \
        php${PHP_VERSION}-opcache \
        libapache2-mod-php${PHP_VERSION}
        WWW_CONF=/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
        PHP_FPM_BIN=php-fpm${PHP_VERSION}
else
    echo "Unknown installation mode: ${INSTALL_MODE}"
    exit 1
fi

# Applying defaults
WWW_CONF=${WWW_CONF:-$(find /etc/php* -name www.conf)}
PHP_BIN=${PHP_BIN:-php}
PHP_FPM_BIN=${PHP_FPM_BIN:-php-fpm}

echo "PHP version installed:"
${PHP_BIN} -v
echo "PHP-FPM version installed:"
${PHP_FPM_BIN} -v

# Installing dd-trace-php
INSTALL_TYPE="${INSTALL_TYPE:-php_installer}"
if [ "$INSTALL_TYPE" = "native_package" ]; then
    echo "Installing dd-trace-php using the OS-specific package installer"
    dpkg -i $(pwd)/build/packages/*.deb
else
    echo "Installing dd-trace-php using the new PHP installer"
    ${PHP_BIN} datadog-setup.php --file $(pwd)/build/packages/dd-library-php-x86_64-linux-gnu.tar.gz --php-bin all
fi

# PHP-FPM setup
# For cases when it defaults to UDS
sed -i 's/^listen = .*$/listen = 9000/g' ${WWW_CONF}
# Passing envs to php-fpm process directly for simplicity. Note that on PHP 5.4 clear_env does not appear in www not
# even commented, so we remove potential existing line and add it at the end of the config file.
sed -i 's/^;*clear_env.*$//g' ${WWW_CONF}
echo 'clear_env = no' >> ${WWW_CONF}
sed -i 's/^;*catch_workers_output.*$/catch_workers_output = yes/g' ${WWW_CONF}

echo "Starting ${PHP_FPM_BIN}"
mkdir -p /run/php/
"${PHP_FPM_BIN}" -D
sleep 0.5

# NGINX setup
cp $(pwd)/dockerfiles/verify_packages/nginx.conf /etc/nginx/nginx.conf
echo "Starting nginx"
nginx
sleep 0.5

# Apache setup
echo "Starting apache"
apachectl start
sleep 0.5

echo "Installation completed successfully"
