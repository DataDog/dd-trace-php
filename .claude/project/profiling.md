# profiling/ — Rust profiler extension

## What it is

A separate Rust `cdylib` extension for continuous CPU/wall-time profiling,
heap allocation, exception, and I/O profiling. Uploads pprof directly to the
agent.

## Key files & dirs

- `profiling/Cargo.toml` — `cdylib`; depends on `libdd-profiling`/`alloc`/
  `common` from libdatadog.
- `profiling/rust-toolchain.toml` — pins Rust (distinct from the workspace
  toolchain).
- `profiling/src/lib.rs` — module entry: `minit`/`rinit`/`prshutdown`, Zend
  interrupt registration.
- `profiling/src/profiling/` — `mod.rs` (`Profiler` + `SampleValues`),
  `interrupts.rs`, `backtrace.rs`, `stack_walking.rs`, `uploader.rs`,
  `thread_utils.rs`.
- `profiling/src/allocation/` — `allocation_ge84.rs` (PHP 8.4+),
  `allocation_le83.rs` (≤8.3).
- `profiling/src/config.rs`, `profiling/src/capi.rs`.
- `profiling/build.rs` — bindgen + `php-config` feature detection.
- `profiling/src/php_ffi.{c,h}`.

## How it fits

`minit` registers a hybrid module + zend_extension; `rinit` starts a
per-request `Profiler` sampling on VM interrupt (~10ms) and hooks
`zend_execute_internal`. Samples wall/CPU/alloc/heap/exception; uploads
pprof via a background thread; `prshutdown` flushes.

Builds independently from the tracer; depends on libdatadog for pprof. The
main tracer looks up `ddog_php_prof_interrupt_function` by symbol to call on
interrupt (see [tracer.md](tracer.md)). Not sidecar-dependent — it uploads
directly (contrast with [sidecar.md](sidecar.md)). `build.rs` reads
`../VERSION`.

## Gotchas

- Pinned `rust-toolchain.toml`, not the workspace default.
- `CARGO_TARGET_DIR` must be set (the Makefile uses `tmp/build_profiler`).
- `cdylib` is release-only — phpt tests fail on debug builds.
- Allocation hook differs: PHP 8.4+ vs ≤8.3 use different modules.
- `io_profiling` is Linux/macOS only.
- `trigger_time_sample` is a debug-only build feature.
- For build/test detail see
  [../ci/building-locally.md](../ci/building-locally.md).
