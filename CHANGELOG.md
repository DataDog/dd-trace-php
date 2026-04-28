Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Fixed
- Fix critical ZTS race condition in INI value refcounting that caused use-after-free crashes under concurrent load #3832
- Ensure a unique installation directory to avoid conflicts with other tools #3835

## Tracer
### Added
- Implement client-side stats computation using shared memory for zero-copy stats delivery, with fallback to socket on first payloads #3756, #3811, #3815, #3836

### Changed
- Use a webserver-wide session ID for sidecar instead of per-fork session IDs, and propagate it to child processes via environment #3828, #3838

### Fixed
- Fix ZTS race condition in `process_tags.serialized` refcounting on shared inter-thread string #3831
- Fix dynamic instrumentation installation regression when enabling via dynamic config #3843
- Handle `APM_MULTI_CONFIG` remote configuration and fix missing data for exception replay #3791
- Fix duration of httpstream and live debugger spans being incorrectly reported as zero #3821
- Fix `instanceof` type aliases for PHP 7.x in live debugger DSL (`integer`/`double` vs `int`/`float`) #3813
- Obfuscate `:name` placeholder parameters in PDO queries for correct DBM correlation #3801
- Fix locale settings breaking ksr resolution #3797 (thank you @jdmaguire for the report!)
- Fix exception in PDO::__construct when signals arrive during database connection setup #3841
- Fix infinite loop in crashtracker runtime stack collection #3845

### Internal
- Add timeout to sidecar info fetcher DataDog/libdatadog#1890
- Allow sidecar worker to be stopped cleanly after fork DataDog/libdatadog#1893
- Use a dedicated sidecar connection per PHP thread, reducing lock contention and enabling per-thread request queuing #3770
- Emit environment variable names in telemetry config (e.g., `DD_TRACE_GENERATE_ROOT_SPAN`) instead of INI dot notation #3783
- Default crash report upload to errors intake to be enabled DataDog/libdatadog#1902
- Flush telemetry on anticipated sidecar shutdown to avoid data loss for short-lived sidecars #3806
- Skip sending empty telemetry payloads DataDog/libdatadog#1894
- Wire telemetry extended heartbeat interval through sidecar SessionConfig DataDog/libdatadog#1882, #3800

## Profiling
### Added
- Support generator unwinding in stack traces #3807

## AppSec
### Fixed
- Fix Remote Config regression in Rust helper #3840
- Fix double-logging of broken connections as errors and improve connection error handling in Rust helper #3792, #3803

### Internal
- Enable helper-rust by default also on PHP 8.4 #3842
- Update vendored libxml2 from 2.15.2 to 2.15.3 #3814
