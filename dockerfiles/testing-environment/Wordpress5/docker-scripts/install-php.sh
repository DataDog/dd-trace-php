#!/usr/bin/env bash

apt update

apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt install -y php7.3-cli php7.3-fpm

rm -rf /var/lib/apt/lists/*
