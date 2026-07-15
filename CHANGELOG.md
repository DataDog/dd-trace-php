Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## Tracer
### Fixed
- Fix `SpanStack::$active` unset corruption #3962
- Fix sandbox not saving/restoring `jit_trace_num` #3964
- Fix `SpanStack` state corruption when tracing objects with deep clone operations #3976
- Fix `request_exec` being issued between requests #3939
- Fix Azure Functions instance name resolution DataDog/libdatadog#2077
- Fix remote config `tracing_sample_rate` missing/null deserialization DataDog/libdatadog#2102

### Internal
- Fix crashtracker metadata: correctly distinguish JIT disabled vs opcache disabled, and correct system INI classification #3965
- Use libdatadog's CSS trace filter implementation, aligning filtering behavior with the agent #3986, DataDog/libdatadog#1985
- Add configurable sidecar connection retry interval #3977, DataDog/libdatadog#2106
- Emit `_dd.svc_src` span tag per Service Override Source Attribution RFC #3948
- Fix duplicate span serialization in the sidecar DataDog/libdatadog#2107

## Profiling
### Added
- Add trampoline for frameless functions (FLF) to correctly capture timings on aarch64 and x86_64 #3595
- Add experimental heap-live profiling for memory leak detection, enabled via `DD_PROFILING_EXPERIMENTAL_HEAP_LIVE_ENABLED` (requires allocation profiling to be active) #3623

### Fixed
- Fix profiler crashes and hangs: stderr fd leak (`O_CLOEXEC` missing) causing child processes to hang, NULL file dereference in timeline error observer on PHP 8.0, and async signal delivery to helper threads causing a segfault on ZTS builds #3364
- Fix macOS release builds for the profiler #3987

### Internal
- Replace `lazy_static` with `std::sync::LazyLock` and optimize `Sapi`/`RefCellExt` #3990
- Simplify profiler name/version string constants to compile-time values #3998

## AppSec
### Changed
- Use the Rust helper for all PHP versions #3991

### Internal
- Implement `waf.error` and `rasp.error` error tracking metrics #3963
- Harden `_assume_utf8` against potential out-of-bounds access #4009
