#!/bin/bash -e

cd /var/www

export DD_TRACE_CLI_ENABLED=false

# Install Composer if not already available
if ! command -v composer >/dev/null 2>&1; then
	curl -sS https://getcomposer.org/installer -o composer-setup.php
	php composer-setup.php --quiet
	rm composer-setup.php
	COMPOSER_BIN="php composer.phar"
else
	COMPOSER_BIN="composer"
fi

$COMPOSER_BIN install --no-dev
chown -R www-data.www-data vendor
