#!/bin/bash -xe
PHP_VERSION=${1:-7.3}

update-alternatives --set php /usr/bin/php${PHP_VERSION}
update-alternatives --set php /usr/bin/php${PHP_VERSION}
update-alternatives --set phar /usr/bin/phar${PHP_VERSION}
update-alternatives --set phar.phar /usr/bin/phar.phar${PHP_VERSION}
update-alternatives --set phpize /usr/bin/phpize${PHP_VERSION}
update-alternatives --set php-config /usr/bin/php-config${PHP_VERSION}

if [[ -x /opt/datadog-php/bin/post-install.sh ]]; then
    /opt/datadog-php/bin/post-install.sh
fi
