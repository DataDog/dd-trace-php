Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Internal
- Implement new SSI configuration telemetry #3301

### Fixed
- Treat opcache.jit=0 as JIT disabled #3337

## Tracer
### Changed
- Add knuth sampling formula #3281
- Add db.type to PDO integration #3350

### Fixed
- Fix dd_patch_zend_call_known_function on early PHP 8 versions on Windows #3326
- Fix DogStatsD client crash when endpoint is unreachable #3344
- Fix trailing ; in tracestate #3354
- Fix DD_TRACE_AGENT_URL panic without scheme and path #3358

### Internal
- Fixup the otel.env.invalid metric name #3284
- Bump the required rust version to 1.84.1 #3299
- Add redaction in autoload_php_file #3313
- Reduce telemetry sent #3316
- Adding telemetry for baggage propagation #3353

## Profiling
### Fixed
- Fewer borrows, less panics on borrows #3295
- Validate opline before access #3319
- Do not call zend_jit_status() on affected versions #3356
- Revert to more stable hooking for allocation profiling #3361

### Internal
- Bump Rust version #3330
- Bump patch versions, drop indexmap #3338

## Application Security Management
### Added
- Truncate input #3250
- Implement ATO v2 functions #3263, #3315
- Schema extraction with DD_APM_TRACING_ENABLED=false #3269
- Parse authorization header #3279
- Add forwarded header and private IP #3345

### Changed
- Update SLO metrics #3239
- Update event obfuscation regex #3290

### Fixed
- Fix rate limiter #3331

### Internal
- Send some telemetry logs from the helper #3236
- Fix warnings on clang-tidy-17 #3287
- Upgrade boost to 1.86 #3289
- Upgrade waf #3323
