#!/usr/bin/env bash

set -e

# Wait until MySQL is really available
MAXCOUNTER=30
COUNTER=1
while ! mysql -u root -h mysql -e "show databases;" > /dev/null 2>&1; do
    sleep 1
    counter=`expr $counter + 1`
    if [ $COUNTER -gt $MAXCOUNTER ]; then
        >&2 echo "We have been waiting for MySQL too long already; failing."
        exit 1
    fi;
done

# Init DB if not exists
EXISTS=$(mysql -h mysql -u root -e "SHOW DATABASES LIKE 'wordpress'")
if [[ -z "$EXISTS" ]]; then
    echo "Initializing DB"
    mysql -h mysql -u root -e "CREATE DATABASE wordpress"
    mysql -h mysql -u root wordpress < /scripts/db-data.sql
fi
