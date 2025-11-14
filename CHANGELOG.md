Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
- Add PHP 8.5 support #3400

## Tracer
### Added
- Implement APM endpoint resource renaming #3415
- Enable dynamic configuration for debugger-related products #3476

### Fixed
- Collect incompletely fetched CurlMulti handles upon destruction #3469
- Safeguard proc_get_span in case proc_assoc_span is not happening #3471
- Skip SSI injector in installer for accurate ini-dir readings #3472
- Make stub file compatible with php 8.4+ parser #3475
- Fix function resolver on PHP 8.0 and PHP 8.1 for targets without HAVE_GCC_GLOBAL_REGS and with active JIT #3482
- Support ENOENT as shm_open failure mode DataDog/libdatadog#1315
  - This fixes a failure mode present on some serverless runtimes.

### Internal
- Add crashtracker support for the sidecar #3453
- Strip error messages from hook telemetry #3449
- Collect runtime crash frames #3479
- Use a dedicated endpoint for enriched logs DataDog/libdatadog#1338

## Profiling
### Internal
- Cleanup I/O profiling code #3406
- Upgrade to libdatadog v23, profiling uses zstd now #3470
- Switch panics to abort #3474

## Application Security Management
### Added
- Print block_id #3444

### Changed
- Upgrade libddwaf and rules #3438
- Adapt security_response_id to latest #3480
