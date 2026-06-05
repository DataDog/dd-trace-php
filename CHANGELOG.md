Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Changed
- The sidecar is now always spawned unconditionally, regardless of configuration #3912

### Internal
- Bump Rust MSRV to 1.87 #3926

## Tracer
### Added
- Add PHP feature flag evaluation including evaluation metrics via OTLP #3906, #3909, #3910, #3911
- Add `dynamic_service` DBM propagation mode as a convenience alias for `service` mode with base hash injection; this mode will replace `service` on the long term #3940
- Add `DD_DBM_ALWAYS_APPEND_SQL_COMMENT` to unconditionally append SQL comments in DBM regardless of sampling #3954
- Recognize PCF Garden container IDs for Cloud Foundry deployments DataDog/libdatadog#2025

### Fixed
- Fix remote config not being delivered after forking #3958
- Fix span pointer invalidation crash during inferred span serialization with `DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED` #3934
- Fix buffer overflow in autoload path construction for oversized class/path names #3932
- Fix Swoole integration parsing the POST body regardless of `DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED` #3931
- Guard JIT blacklist rewrite to prevent crashes with non-tracing JIT metadata #3929
- Fix OTel polyfill post hooks with `:void` return type overwriting the instrumented function's actual return value #3920
- Fix span stats broken for nested services due to incorrect `top_level` span detection #3916
- Fix `php.compilation.total_time_ms` reporting values 1000x too large (microseconds labeled as milliseconds) #3915 (thank you @dortort!)
- Fix memory corruption of INIs in ZTS builds #3898
- Fix data race in curl header assignment (non-atomic write to `_Atomic` field) #3945
- Fix sample rate normalization to 0..1 range, preventing incorrect Knuth hash computation #3935
- Fix multi-request failures caused by incorrect rinit ordering after tracer/ext split #3946

### Internal
- Split the tracer code into a separate `tracer/` directory within the extension #3912
- Improve crashtracker reliability: socket-based thread collection, incomplete stack handling, and `language:native` tag DataDog/libdatadog#2080, DataDog/libdatadog#2079, DataDog/libdatadog#2083

## Profiling
### Fixed
- Fix macOS release builds for the profiler #3923

### Internal
- Support PHP DEBUG builds for the profiler, enabling ASAN testing in CI #3908

## AppSec
### Added
- Collect `x-datadog-endpoint-scan` and `x-datadog-security-test` security testing headers as span tags on HTTP entry spans, independent of `DD_TRACE_HEADER_TAGS` and AppSec enablement #3925

### Fixed
- Fix out-of-bounds iteration in PHP <8.1 backtrace `HashTable` loop in the AppSec backtrace collection path #3933
