Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Fixed
- Surface SSI incompatible runtime (Xdebug, OPcache JIT) as `incompatible_runtime` injection metadata, so diagnostics can explain why profiles appear but traces are missing #4021
- Sanitize crash report type and message for unhandled exceptions DataDog/libdatadog#2148

### Changed
- Crash reports for unhandled exceptions now include native stacks for every thread, including the crashing thread DataDog/libdatadog#2155

### Internal
- Exclude PHP build PRs from the config telemetry monitor #3982

## Tracer
### Fixed
- Fix unbounded memory growth (leading to OOM) from `curl_multi_exec` parent spans when the span limit is already reached #4030
- Fix a Live Debugger probe-removal use-after-free, tags leak, and PHP 7.x Windows crash #4036

### Internal
- Signal whether the service name was user-set or auto-resolved via new `svc.user`/`svc.auto` process tags #3921, DataDog/libdatadog#2053
- Correct published type/default metadata for `OTEL_*` SDK-sourced configs #4005
- Avoid redundant sidecar notifications when updating per-request Dynamic Instrumentation config DataDog/libdatadog#2146

## Profiling
### Fixed
- Fix an illegal-instruction crash on Apple AArch64 in generated PHP frameless-call trampolines #4038

### Changed
- Faster string hashing and 3.7-5.3x faster statistical (Poisson) sampling via updated Rust dependencies #4038

## AppSec
### Fixed
- Avoid occasional "ruleset not found" errors after redeploys/upgrades by embedding a default ruleset in the Rust helper #4037
- Pick up an upstream libddwaf fix avoiding an exception during user-resource comparison (bump to 2.0.1) #4033

### Internal
- Avoid an extra buffer copy in the Rust helper's client protocol #4029
