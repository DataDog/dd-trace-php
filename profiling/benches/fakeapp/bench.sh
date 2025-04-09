#!/bin/bash

if [[ "$OSTYPE" != "linux-gnu"* ]]; then
    echo "ERROR: This script only works on Linux."
    exit 1
fi

# 3 is the number of unique samples triggered, it's just known statically for the app
rm -v trigger-{0..2}.txt

set -eu

cargo build --release --features "allocation_profiling,exception_profiling,io_profiling,timeline,tracing-subscriber,trigger_time_sample"

RUST_LOG=trace php -c . -dextension=$PWD/../../../target/release/libdatadog_php_profiling.so -S 0.0.0.0:8080 -t public &> output.txt &
pid=$!

# https://measuringu.com/sample-size-recommendations/
# For 90% confidence with a margin of error lower than some percent:
# 5%: 268
# 4%: 421
# 3%: 749
# Their numbers are perhaps not an exact match for our use-case, but it's at
# least better justified than pulling them out of the air.
target=749

sleep 1 # just because it takes time for the thing to be ready to serve requests

i=0
time (while [ $i -lt $target ]
    do
        i=$((i+1))
        curl 0.0.0.0:8080/blog &>/dev/null
    done;
    echo "Ran $i iterations"
)

kill -n TERM "$pid"

< output.txt awk -F'[ =]' '/collect_stack_sample/ { print $7 }' \
    | awk '{ print $0 > "trigger-" (NR-1)%3 ".txt" }'

wc -l trigger-{0..2}.txt

