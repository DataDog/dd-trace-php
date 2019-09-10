#!/bin/bash -xe
switch_php 7.1

for cnt in {1..100}; do
    mysql -h mysql -e 'CREATE DATABASE IF NOT EXISTS wordpress_functional_testing;' && break || true
    sleep 1
done

vendor/bin/phpunit
