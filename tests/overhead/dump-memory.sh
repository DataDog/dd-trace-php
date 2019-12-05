#!/bin/bash

grep rw-p /proc/$1/maps \
| sed -n 's/^\([0-9a-f]*\)-\([0-9a-f]*\) .*$/\1 \2/p' \
| while read start stop; do \
    gdb --batch --pid $1 -ex \
        "dump memory $1-$start-$stop.dump 0x$start 0x$stop"; \
done
