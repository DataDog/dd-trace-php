#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Install using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin=all

mv /usr/local/sbin/php-fpm /usr/local/sbin/php-fpm7.4
cat <<'EOT' > /usr/local/sbin/php-fpm
#!/usr/bin/env bash
/usr/local/sbin/php-fpm7.4 -F -O 2>&1
EOT
chmod +x /usr/local/sbin/php-fpm

assert_ddtrace_version "${version}" php
assert_ddtrace_version "${version}" php-fpm7.4
