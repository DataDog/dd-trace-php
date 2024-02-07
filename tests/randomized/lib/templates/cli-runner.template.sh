#!/usr/bin/env sh

set -e

{{envs}} php {{inis}} -d datadog.trace.log_file=/results/dd_php_error.log -d error_log=/var/log/php/error.log /var/www/html/public/long_running_script.php \
    --seed={{seed}} \
    --repeat=100 \
    --file=/results/memory.out
