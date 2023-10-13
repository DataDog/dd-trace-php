#!/usr/bin/env bash

set -ex

cd ../profiling/

cargo build --release --features trigger_time_samples

sirun benches/memory.json

sed -i -e "s/crate-type.*$/crate-type = [\"rlib\"]/g" Cargo.toml

cargo bench --features stack_walking_tests -- --noplot
