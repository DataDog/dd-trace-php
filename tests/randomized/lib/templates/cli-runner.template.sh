#!/usr/bin/env sh

set -e

{{envs}} php {{inis}} /var/www/html/public/long_running_script.php \
    --seed={{seed}} \
    --repeat=1000 \
    --file=/results/memory.out
