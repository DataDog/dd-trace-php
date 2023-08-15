#!/usr/bin/env bash

set -ex

cd ../profiling/
sed -i -e "s/crate-type.*$/crate-type = [\"rlib\"]/g" Cargo.toml

# Symlink clang-16 to clang so we can use the lto helper
ln -s "$(command -v clang-16)" /usr/local/bin/clang

../profiling/cargo-lto.sh bench --features stack_walking_tests -- --noplot
