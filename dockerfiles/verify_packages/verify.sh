#!/usr/bin/env sh

set -e

export DD_REMOTE_CONFIG_ENABLED=false

wait_for_trace() {
    TRACE_REPLAY=""
    attempt=1
    while [ "$attempt" -le 10 ]; do
        sleep 1
        TRACE_REPLAY=$(curl -s -L request-replayer/replay)
        if [ "${TRACE_REPLAY#*trace_id}" != "$TRACE_REPLAY" ]; then
            return 0
        fi

        attempt=$((attempt + 1))
    done

    return 1
}

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
CLI_OUTPUT=$(${DD_TRACE_PHP_BIN} /var/www/html/index.php)
if [ "${CLI_OUTPUT}" != "hi" ]; then
    echo "Error: expected request output is 'hi'. Actual:\n${APACHE_OUTPUT}"
    exit 1
else
    echo "Request output is correct"
fi

# Trace exists
if ! wait_for_trace; then
    echo "Error: traces have not been sent correctly. From request replayer:\n${TRACE_REPLAY}"
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
    exit 1
else
    echo "Request output is correct"
fi

# Trace exists
if ! wait_for_trace; then
    echo "Error: traces have not been sent correctly. From request replayer:\n${TRACE_REPLAY}"
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
        exit 1
    else
        echo "Request output is correct"
    fi

    # Trace exists
    if ! wait_for_trace; then
        echo "Error: traces have not been sent correctly. From request replayer:\n${TRACE_REPLAY}"
        exit 1
    else
        echo "Traces have been sent is correct"
    fi
    echo "APACHE verification: SUCCESS"
else
    echo "APACHE verification: SKIPPED"
fi
echo "##########################################################################"
