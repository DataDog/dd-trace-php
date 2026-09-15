#!/bin/bash -e

sed -i '1i extension=/grpc/grpc.so' /etc/php/php.ini
sed -i '1i extension=/profiler/datadog-profiling.so' /etc/php/php.ini

DD_PROFILING_ENABLED=0 php -n \
  -d extension=/profiler/datadog-profiling.so \
  -d extension=/grpc/grpc.so -r \
  'exit(!PHP_ZTS && phpversion("grpc") === "1.83.1" &&
    extension_loaded("datadog-profiling") ? 0 : 1);'

sed -i \
  -e 's/pm.max_children = .*/pm.max_children = 1/' \
  -e 's/pm.max_requests = .*/pm.max_requests = 0/' \
  /etc/php-fpm.d/www.conf

kill -9 $(pgrep php-fpm)
php-fpm -y /etc/php-fpm.conf -c /etc/php/php.ini
