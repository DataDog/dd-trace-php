Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Added
- Support the stable OpenTelemetry `deployment.environment.name` resource attribute, mapped to `DD_ENV` (in addition to the legacy `deployment.environment`) #4148

### Internal
- Exclude `DD_API_KEY` and OTLP exporter header values from configuration telemetry #3961

## Tracer
### Changed
- Add a 1 MB size limit to Dynamic Instrumentation capture snapshots #4126

### Fixed
- Fix missing/mis-tagged `drupal.theme.render` spans on Drupal >= 11.3 and under early-returning renders #4145
- Fix sidecar reconnection error handling DataDog/libdatadog#2463
- Fix a sidecar listening-socket leak across forks DataDog/libdatadog#2447

### Internal
- Reduce reconnect overhead for periodic background HTTP requests in the sidecar (telemetry/trace flushes) DataDog/libdatadog#2440
- Reduce the number of HTTP requests sent for shutdown telemetry DataDog/libdatadog#2435
- Feature Flag Evaluation: support arbitrary semver version formats and add serial-id tracking to exposure events DataDog/libdatadog#2413, DataDog/libdatadog#2402
- Improve crash-report accuracy by filtering stack frames above the faulting frame DataDog/libdatadog#2428
- Fix -flto -ffat-lto-objects builds DataDog/libdatadog#2460

## Profiling
### Changed
- Improve profiler throughput by ~1% by gating debug-only runtime cache stats behind a build feature #4130

### Internal
- Align zstd compression behavior across targets in the profiler's uploader DataDog/libdatadog#2400

## AppSec
### Internal
- Move appsec communication onto the sidecar; the appsec helper is now built into the sidecar instead of as a separate binary, and the legacy C++ helper is removed #3725
