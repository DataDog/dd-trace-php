#!/usr/bin/env bash

apt update

apt install -y \
    curl \
    nginx \
    supervisord \
    unzip \
    vim \
    wget \
    zip

rm -rf /var/lib/apt/lists/*
