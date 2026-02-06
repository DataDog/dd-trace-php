#!/usr/bin/env sh

set -e

mkdir -p /run/nginx

apk add --no-cache nginx curl

# Installing php
if [ ! -z "${PHP_PACKAGE}" ]; then
    apk add --no-cache ${PHP_PACKAGE}
fi

# Preparing PHP
if [ -z "$PHP_BIN" ]; then
    PHP_BIN=$(command -v php || true)
fi
if [ -z "$PHP_BIN" ]; then
    PHP_BIN=$(command -v php8 || true)
fi
if [ -z "$PHP_BIN" ]; then
    PHP_BIN=$(command -v php85 || true)
fi
if [ -z "$PHP_BIN" ]; then
    PHP_BIN=$(command -v php84 || true)
fi
if [ -z "$PHP_BIN" ]; then
    PHP_BIN=$(command -v php83 || true)
fi
if [ -z "$PHP_BIN" ]; then
    PHP_BIN=$(command -v php82 || true)
fi
if [ -z "$PHP_BIN" ]; then
    PHP_BIN=$(command -v php81 || true)
fi
if [ -z "$PHP_BIN" ]; then
    PHP_BIN=$(command -v php7 || true)
fi

# Installing dd-trace-php
INSTALL_TYPE="${INSTALL_TYPE:-php_installer}"
if [ "$INSTALL_TYPE" = "native_package" ]; then
    echo "Installing dd-trace-php using the OS-specific package installer"
    apk add --no-cache $(pwd)/build/packages/*$(uname -m)*.apk --allow-untrusted
else
    echo "Installing dd-trace-php using the new PHP installer"
    apk add --no-cache libgcc
    installable_bundle=$(find "$(pwd)/build/packages" -maxdepth 1 -name "dd-library-php-*-$(uname -m)-linux-musl.tar.gz")
    $PHP_BIN datadog-setup.php --file "$installable_bundle" --php-bin all --enable-appsec
fi

# Preparing NGINX
# Adding www-data in systems where it does not exists
adduser -D -S -G www-data www-data || true
cp $(pwd)/dockerfiles/verify_packages/nginx.conf /etc/nginx/nginx.conf

# Preparing PHP-FPM
if [ -z "$PHP_FPM_BIN" ]; then
    PHP_FPM_BIN=$(command -v php-fpm || true)
fi
if [ -z "$PHP_FPM_BIN" ]; then
    PHP_FPM_BIN=$(command -v php-fpm85 || true)
fi
if [ -z "$PHP_FPM_BIN" ]; then
    PHP_FPM_BIN=$(command -v php-fpm84 || true)
fi
if [ -z "$PHP_FPM_BIN" ]; then
    PHP_FPM_BIN=$(command -v php-fpm83 || true)
fi
if [ -z "$PHP_FPM_BIN" ]; then
    PHP_FPM_BIN=$(command -v php-fpm82 || true)
fi
if [ -z "$PHP_FPM_BIN" ]; then
    PHP_FPM_BIN=$(command -v php-fpm81 || true)
fi
if [ -z "$PHP_FPM_BIN" ]; then
    PHP_FPM_BIN=$(command -v php-fpm8 || true)
fi
if [ -z "$PHP_FPM_BIN" ]; then
    PHP_FPM_BIN=$(command -v php-fpm7 || true)
fi
if [ -z "$PHP_FPM_BIN" ]; then
    PHP_FPM_BIN=$(command -v php-fpm5 || true)
fi

WWW_CONF=/etc/php/php-fpm.d/www.conf
if [ ! -f "${WWW_CONF}" ]; then
    WWW_CONF=/usr/local/etc/php-fpm.d/www.conf
fi
if [ ! -f "${WWW_CONF}" ]; then
    # Alpine 3.22+
    WWW_CONF=/etc/php85/php-fpm.d/www.conf
fi
if [ ! -f "${WWW_CONF}" ]; then
    # Alpine 3.21
    WWW_CONF=/etc/php84/php-fpm.d/www.conf
fi
if [ ! -f "${WWW_CONF}" ]; then
    # Alpine 3.20
    WWW_CONF=/etc/php83/php-fpm.d/www.conf
fi
if [ ! -f "${WWW_CONF}" ]; then
    # Alpine 3.19
    WWW_CONF=/etc/php82/php-fpm.d/www.conf
fi
if [ ! -f "${WWW_CONF}" ]; then
    # Alpine 3.17
    WWW_CONF=/etc/php81/php-fpm.d/www.conf
fi
if [ ! -f "${WWW_CONF}" ]; then
    WWW_CONF=/etc/php8/php-fpm.d/www.conf
fi
if [ ! -f "${WWW_CONF}" ]; then
    WWW_CONF=/etc/php7/php-fpm.d/www.conf
fi
# For cases when it defaults to UDS
sed -i 's/^listen = .*$/listen = 127.0.0.1:9000/g' $(dirname -- ${WWW_CONF})/*.conf
# Passing envs to php-fpm process directly for simplicity. Note that on PHP 5.4 clear_env does not appear in www not
# even commented, so we remove potential existing line and add it at the end of the config file.
sed -i 's/^;*clear_env.*$//g' ${WWW_CONF}
echo 'clear_env = no' >> ${WWW_CONF}
# Enabling logs
sed -i 's/^;*catch_workers_output.*$/catch_workers_output = yes/g' ${WWW_CONF}

# Starting services
${PHP_FPM_BIN} -D
sleep 0.5
nginx
sleep 0.5

echo "Installation completed successfully"
