#!/usr/bin/env sh

set -e

# Retry a command up to 3 times with 10s backoff before signaling infra failure.
# All failures in this script are infrastructure failures (package downloads,
# network), not test failures — so exhausted retries exit 75 (EX_TEMPFAIL)
# to trigger GitLab's infra-retry rule rather than marking the job as failed.
retry_or_tempfail() {
    local n=1
    until "$@"; do
        if [ "$n" -ge 3 ]; then
            echo "Infrastructure command failed after $n attempts, signaling retry (exit 75): $*" >&2
            exit 75
        fi
        echo "Command failed (attempt $n/3): $* — retrying in 10s..." >&2
        n=$((n + 1))
        sleep 10
    done
}

# Bound apt: a stalled mirror fetch otherwise hangs until the 1 h job timeout.
apt_get() { timeout 300 apt-get -o Acquire::http::Timeout=30 -o Acquire::Retries=3 "$@"; }

# Common installations
retry_or_tempfail apt_get update
retry_or_tempfail apt_get install -y \
    apt-transport-https \
    lsb-release \
    ca-certificates \
    curl \
    nginx \
    apache2 \
    procps \
    gnupg

retry_or_tempfail curl -sSfL -o /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
sh -c 'echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list'
retry_or_tempfail apt_get update
retry_or_tempfail apt_get install -y \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-opcache \
    libapache2-mod-php${PHP_VERSION}
    WWW_CONF=/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
    PHP_FPM_BIN=php-fpm${PHP_VERSION}

echo "PHP installation completed successfully"
