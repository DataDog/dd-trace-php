#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Install using the php installer
new_version="0.79.0"
generate_installers "${new_version}"
php ./build/packages/datadog-setup.php --php-bin=all

mv /usr/local/sbin/php-fpm /usr/local/sbin/php-fpm7.4
cat <<'EOT' > /usr/local/sbin/php-fpm
#!/usr/bin/env bash
/usr/local/sbin/php-fpm7.4 -F -O 2>&1
EOT
chmod +x /usr/local/sbin/php-fpm

assert_ddtrace_version "${new_version}" php
assert_ddtrace_version "${new_version}" php-fpm7.4
