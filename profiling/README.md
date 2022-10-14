# PHP Profiler

The profiler is implemented in Rust. To see the currently required Rust
version, refer to the [rust-toolchain](rust-toolchain) file. The profiler
requires PHP 7.1+, and does not support debug nor ZTS builds. There are bits of
ZTS support in the build system and profiler, but it's not complete.

## Compiling

The command `cargo build` will run the [build.rs](build.rs) script, which is
how it adapts to various PHP versions. The
[bindgen](https://crates.io/crates/bindgen) crate is used to generate Rust
bindings to the Zend Engine. Although bindgen is pretty good, there are things
like complex macro expansions which it doesn't understand, so there is a bit of
C code to do things that Rust/bindgen isn't good at.

The [src/bindings/mod.rs](src/bindings/mod.rs) file manually defines certain
structs and function definitions instead of letting bindgen handle it. This
allows us to gloss over minor differences in const-correctness in the engine
definitions across versions, as well as provide more idiomatic types in some
cases where they are ABI compatible.

## Testing

The command `cargo test` will run the tests on the profiler.

To see if the profiler is recognised by your PHP version as an extension you
may run `php -d extension=target/debug/libdatadog_php_profiling.so --ri
datadog-profiling` and check the output.

The following commands will help you run the PHPT tests from
[tests/phpt/](tests/phpt):

```sh
cp -v "$(find $(php-config --prefix) -name run-tests.php)" .
export TEST_PHP_EXECUTABLE=$(which php)
php run-tests.php -d extension=target/release/libdatadog_php_profiling.dylib tests/phpt
```

Be aware that the PHPT tests will fail with the debug version of the profiler,
if you haven't already, build the release version with `cargo build --release`.

## Troubleshooting

#### ld: symbol(s) not found for architecture arm64

If your linker is complaining about not finding certain symbols, you might be
missing your architecture in the [.cargo/config](.cargo/config) file. You
should be able to fix this problem by adding your target as shown by `rustc
-vV`.
