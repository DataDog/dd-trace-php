#!/usr/bin/env sh

set -e

PORT=$1
TEMPLATE=$2

mkdir -p /var/run/ /var/log/redis/ /var/lib/redis/${PORT}

cp /${TEMPLATE} /redis.${PORT}.conf

sed -i "s/INSTANCE_PORT/${PORT}/g" /redis.${PORT}.conf
