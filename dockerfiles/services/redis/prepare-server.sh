#!/usr/bin/env sh

set -e

PORT=$1

mkdir -p /var/run/ /var/log/redis/ /var/lib/redis/${PORT}

cp /conf_template.conf /redis.${PORT}.conf

sed -i "s/INSTANCE_PORT/${PORT}/g" /redis.${PORT}.conf
