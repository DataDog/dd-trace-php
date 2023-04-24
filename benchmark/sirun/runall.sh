#!/bin/bash
source ~/.cargo/env
if [ -n "$1" ]; then
    make CANDIDATE_DIR=$1
else
    make
fi
