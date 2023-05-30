#!/usr/bin/env bash

set -ex

cd ../profiling/
sed -i ".bck" -e "s/crate-type.*$/crate-type = [\"rlib\"]/g" Cargo.toml

cargo bench --features stack_walking_tests

mv Cargo.toml.bck Cargo.toml
