FROM php:7.2

RUN apt-get update \
    && apt-get install -y \
        gdb \
    && rm -rf /var/lib/apt/lists/*

RUN ulimit -c unlimited

WORKDIR /app

RUN bash
