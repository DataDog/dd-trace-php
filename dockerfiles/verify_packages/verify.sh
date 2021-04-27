#!/usr/bin/env sh

set -e

# Installing generic dependencies. OS_ID='centos'|'debian'|'alpine'
OS_ID=$(. /etc/os-release; echo $ID)
sh $(pwd)/dockerfiles/verify_packages/${OS_ID}/install.sh

# Waiting for all services to startup
sleep 1

# Preparing html file to be served
mkdir -p /var/www/html/
echo "<?php echo 'hi'; ?>" > /var/www/html/index.php




echo "##########################################################################"
echo "CLI verification"

curl -s -L request-replayer/clear-dumped-data

# We attempt in this order the following binary names:
#    1. php
#    2. php7 (some versions install php 7.x to this binary)
#    3. php5 (some versions install php 5.x to this binary)
DD_TRACE_PHP_BIN=$(command -v php || true)
if [ -z "$DD_TRACE_PHP_BIN" ]; then
    DD_TRACE_PHP_BIN=$(command -v php7 || true)
fi
if [ -z "$DD_TRACE_PHP_BIN" ]; then
    DD_TRACE_PHP_BIN=$(command -v php5 || true)
fi

echo "PHP version: $(php -v)"

# Script output
CLI_OUTPUT=$(DD_TRACE_CLI_ENABLED=true ${DD_TRACE_PHP_BIN} /var/www/html/index.php)
if [ ! "${CLI_OUTPUT}" == "hi" ]; then
    echo "Error: expected request output is 'hi'. Actual:\n${APACHE_OUTPUT}"
    exit 1
else
    echo "Request output is correct"
fi

# Trace exists
sleep 1
CLI_TRACES=$(curl -s -L request-replayer/replay)
# sh compatible way to do string contains
if [ "${CLI_TRACES#*trace_id}" == "${CLI_TRACES}" ]; then
    echo "Error: traces have not been sent correctly. From request replayer:\n${CLI_TRACES}"
    exit 1
else
    echo "Traces have been sent is correct"
fi
echo "CLI verification: SUCCESS"
echo "##########################################################################"




echo "##########################################################################"
echo "PHP-FPM/NGINX verification"

curl -s -L request-replayer/clear-dumped-data

# Request output
NGINX_OUTPUT=$(curl -s -L localhost:8080)
if [ ! "${NGINX_OUTPUT}" == "hi" ]; then
    echo "Error: expected request output is 'hi'. Actual:\n${NGINX_OUTPUT}"
    exit 1
else
    echo "Request output is correct"
fi

# Trace exists: waiting more than DD_TRACE_AGENT_FLUSH_INTERVAL=1000
sleep 2
NGINX_TRACES=$(curl -s -L request-replayer/replay)
# sh compatible way to do string contains
if [ "${NGINX_TRACES#*trace_id}" == "${NGINX_TRACES}" ]; then
    echo "Error: traces have not been sent correctly. From request replayer:\n${NGINX_TRACES}"
    exit 1
else
    echo "Traces have been sent is correct"
fi
echo "PHP-FPM/NGINX verification: SUCCESS"
echo "##########################################################################"




echo "##########################################################################"
echo "APACHE verification"

if [ "${VERIFY_APACHE}" != "no" ]; then
    curl -s -L request-replayer/clear-dumped-data

    # Request output
    APACHE_OUTPUT=$(curl -s -L localhost/index.php)
    if [ ! "${APACHE_OUTPUT}" == "hi" ]; then
        echo "Error: expected request output is 'hi'. Actual:\n${APACHE_OUTPUT}"
        exit 1
    else
        echo "Request output is correct"
    fi

    # Trace exists: waiting more than DD_TRACE_AGENT_FLUSH_INTERVAL=1000
    sleep 2
    APACHE_TRACES=$(curl -s -L request-replayer/replay)
    # sh compatible way to do string contains
    if [ "${APACHE_TRACES#*trace_id}" == "${APACHE_TRACES}" ]; then
        echo "Error: traces have not been sent correctly. From request replayer:\n${APACHE_TRACES}"
        exit 1
    else
        echo "Traces have been sent is correct"
    fi
    echo "APACHE verification: SUCCESS"
else
    echo "APACHE verification: SKIPPED"
fi
echo "##########################################################################"
