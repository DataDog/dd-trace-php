#!/usr/bin/env bash

set -e

apt-get update

apt-get install -y \
    autoconf \
    automake \
    bison \
    build-essential \
    curl \
    gettext \
    git \
    libcurl4-gnutls-dev \
    libssl-dev \
    libtool \
    libxml2-dev \
    libfcgi0ldbl \
    nginx \
    re2c \
    supervisor \
    unzip \
    wget \
    vim \
    zip

rm -rf /var/lib/apt/lists/*
