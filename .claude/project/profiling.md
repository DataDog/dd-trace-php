# profiling/ — PHP SDK profiling module

## What it is

The Rust profiling product for continuous CPU/wall-time, allocation, heap,
exception, and I/O profiling. It can be built into the combined `ddtrace.so`
or, for OTel and future integrations, as the standalone
`datadog-profiling.so`. Profiles upload directly to the agent.

## Key files and directories

- Root `Cargo.toml` — owns the `datadog-php` package and its `profiling`
  feature; profiling is not a separate Cargo package.
- `profiling/src/lib.rs` — shared profiler lifecycle and the standalone PHP
  module/Zend-extension wrappers.
- `profiling/src/lifecycle.h` — C lifecycle API used by combined `ddtrace.so`.
- `profiling/src/profiling/` — profiler, interrupts, stack walking, uploader,
  and thread utilities.
- `profiling/src/allocation/` — version-specific PHP allocation hooks.
- `profiling/configuration.h` and `profiling/src/configuration.c` — generated
  configuration declarations and the standalone ZAI host.
- `profiling/src/config.rs`, `profiling/src/capi.rs`.
- `profiling/build.rs` — bindgen, PHP capability detection, and private C
  support compiled as part of the root package build.
- `profiling/src/php_ffi.{c,h}`.

## How it fits

Both artifacts call the same profiler lifecycle implementation. Standalone
mode supplies its own PHP module, Zend extension, globals, and ZAI runtime.
Combined mode is dispatched by `ext/datadog.c`, uses ddtrace-owned globals and
aggregate ZAI configuration, and links tracer/common integrations directly.
The standalone profiler may coexist with the OTel SDK tracer, but loading it
alongside any `ddtrace.so` artifact is unsupported and rejected.

## Building

Loadable PHP artifacts must be built from the repository root through
phpize/configure/Make; direct Cargo output is not a supported extension:

```sh
phpize
./configure --disable-ddtrace-tracer --enable-ddtrace-profiling # standalone
# or: ./configure --enable-ddtrace-tracer --enable-ddtrace-profiling # combined
make -j"$(nproc)"
```

Make/configure select the Cargo features, target directory, PHP headers,
sanitizer flags, and output module name. Cargo remains appropriate for Rust
unit tests, clippy, and benchmarks, but not extension validation.

## Gotchas

- The toolchain is pinned by the repository's Rust toolchain files.
- PHPT and integration validation must load `modules/datadog-profiling.so` or
  `modules/ddtrace.so`, never a Cargo target-directory cdylib.
- Allocation hooks differ between PHP 8.4+ and earlier PHP versions.
- `io_profiling` is Linux/macOS only.
- `trigger_time_sample` is a test/benchmark build feature.
- For build/test detail see
  [../ci/building-locally.md](../ci/building-locally.md).
