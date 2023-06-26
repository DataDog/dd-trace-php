#!/usr/bin/env bash

set -ex

cd ../profiling/
sed -i -e "s/crate-type.*$/crate-type = [\"rlib\"]/g" Cargo.toml

cargo bench --features stack_walking_tests -- --noplot
