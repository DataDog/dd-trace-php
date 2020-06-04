#!/usr/bin/env bash

set -e

apt update

apt install -y \
    curl \
    nginx \
    supervisor \
    unzip \
    vim \
    wget \
    zip

rm -rf /var/lib/apt/lists/*

# remove unused supervisord files that will be provided from outside
rm -rf \
    /etc/supervisord/*
