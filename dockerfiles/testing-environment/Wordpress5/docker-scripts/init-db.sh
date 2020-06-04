#!/usr/bin/env bash

set -e

EXISTS=$(mysql -h mysql -u root -e "SHOW DATABASES LIKE 'wordpress'")

if [[ -z "$EXISTS" ]]; then
    echo "Initializing the DB"
    sleep 10
    mysql -h mysql -u root -e "CREATE DATABASE wordpress"
    mysql -h mysql -u root wordpress < /scripts/db-data.sql
fi
