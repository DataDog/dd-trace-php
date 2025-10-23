#!/usr/bin/env sh

set -e

export DD_REMOTE_CONFIG_ENABLED=false
export DD_TRACE_DEBUG=1
export DD_TRACE_LOG_FILE=/tmp/log

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
DD_TRACE_PHP_BIN=$(command -v php || true)
if [ -z "$DD_TRACE_PHP_BIN" ]; then
    DD_TRACE_PHP_BIN=$(command -v php7 || true)
fi

echo "PHP version: $(${DD_TRACE_PHP_BIN} -v)"

# Script output
# Explicitly enable CLI tracing and force flush on shutdown for installer-based installations
# Set high flush interval to prevent premature flush of empty traces before shutdown
CLI_OUTPUT=$(${DD_TRACE_PHP_BIN} -d datadog.trace.cli_enabled=1 -d datadog.trace.force_flush_on_shutdown=1 -d datadog.trace.agent_flush_interval=999999 /var/www/html/index.php)
if [ "${CLI_OUTPUT}" != "hi" ]; then
    echo "Error: expected request output is 'hi'. Actual:\n${APACHE_OUTPUT}"
    cat /tmp/log
    exit 1
else
    echo "Request output is correct"
fi

# Trace exists - increased sleep time for installer-based installations with shutdown flush
sleep 3
CLI_TRACES=$(curl -s -L request-replayer/replay)
# sh compatible way to do string contains
if [ "${CLI_TRACES#*trace_id}" = "${CLI_TRACES}" ]; then
    echo "Error: traces have not been sent correctly. From request replayer:\n${CLI_TRACES}"
    cat /tmp/log
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
if [ "${NGINX_OUTPUT}" != "hi" ]; then
    echo "Error: expected request output is 'hi'. Actual:\n${NGINX_OUTPUT}"
    cat /tmp/log
    exit 1
else
    echo "Request output is correct"
fi

sleep 3
NGINX_TRACES=$(curl -s -L request-replayer/replay)
# sh compatible way to do string contains
if [ "${NGINX_TRACES#*trace_id}" = "${NGINX_TRACES}" ]; then
    echo "Error: traces have not been sent correctly. From request replayer:\n${NGINX_TRACES}"
    cat /tmp/log
    exit 1
else
    echo "Traces have been sent is correct"
fi
echo "PHP-FPM/NGINX verification: SUCCESS"
echo "##########################################################################"




echo "##########################################################################"
echo "APACHE verification"

if [ "${VERIFY_APACHE:-yes}" != "no" ]; then
    curl -s -L request-replayer/clear-dumped-data

    # Request output
    APACHE_OUTPUT=$(curl -s -L localhost:8081/index.php)
    if [ "${APACHE_OUTPUT}" != "hi" ]; then
        echo "Error: expected request output is 'hi'. Actual:\n${APACHE_OUTPUT}"
        cat /tmp/log
        exit 1
    else
        echo "Request output is correct"
    fi

    sleep 3
    APACHE_TRACES=$(curl -s -L request-replayer/replay)
    # sh compatible way to do string contains
    if [ "${APACHE_TRACES#*trace_id}" = "${APACHE_TRACES}" ]; then
        echo "Error: traces have not been sent correctly. From request replayer:\n${APACHE_TRACES}"
        cat /tmp/log
        exit 1
    else
        echo "Traces have been sent is correct"
    fi
    echo "APACHE verification: SUCCESS"
else
    echo "APACHE verification: SKIPPED"
fi
echo "##########################################################################"
