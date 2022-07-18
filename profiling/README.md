The profiler is implemented in Rust. To see the currently required Rust
version, refer to the [rust-toolchain](rust-toolchain) file. The profiler
requires PHP 7.1+, and does not support debug nor ZTS builds. There are bits
of ZTS support in the build system and profiler, but it's not complete.

The command `cargo build` will run the [build.rs](build.rs) script, which is
how it adapts to various PHP versions. The
[bindgen](https://crates.io/crates/bindgen) crate is used to generate Rust
bindings to the Zend Engine. Although bindgen is pretty good, there are things
like complex macro expansions which it doesn't understand, so there is a bit
of C code to do things that Rust/bindgen isn't good at.

The [src/bindings.rs](src/bindings.rs) file manually defines certain structs
and function definitions instead of letting bindgen handle it. This allows us
to gloss over minor differences in const-correctness in the engine definitions
across versions, as well as provide more idiomatic types in some cases where
they are ABI compatible.
