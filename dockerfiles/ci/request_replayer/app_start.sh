#!/usr/bin/env bash

set -e

composer install

php -S 0.0.0.0:80 index.php
