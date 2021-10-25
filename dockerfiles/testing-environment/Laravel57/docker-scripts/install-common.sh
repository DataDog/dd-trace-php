#!/usr/bin/env bash

set -e

apt-get update

apt-get install -y \
    autoconf \
    automake \
    bison \
    build-essential \
    curl \
    gdb \
    gettext \
    git \
    libcurl4-gnutls-dev \
    libfcgi0ldbl \
    libssl-dev \
    libtool \
    libxml2-dev \
    nginx \
    re2c \
    supervisor \
    unzip \
    valgrind \
    vim \
    wget \
    zip

rm -rf /var/lib/apt/lists/*
