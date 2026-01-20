Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Fixed
- Fix packaging apks for new alpine versions #3555
- Fix http_response_header deprecation in installer #3553

## Tracer
### Added
- Support OpenTelemetry Metrics #3487
- Adds process_tags to the first span of each tracing payload #3566
- Distributed tracing header injection in HyperF/Swoole environments #3544
- Stream context integration with HTTP method #3534

### Changed
- Enable http.endpoint calculation when appsec is explicitly enabled #3556

### Fixed
- Fix panic after bailout in previous request #3537
- Avoid curl_getenv for unix:// too #3540
- Correct a bug on prepared statement regarding DBM correlation #3545
- Fix onclose in cycle collected spans #3587
- prefer poll() for channel DataDog/libdatadog#1443
- AWS lambda also can return EACCESS for shm_open DataDog/libdatadog#1446

### Internal
- bump libdatadog to v25.0.0 #3568

## Profiler
### Changed
- Optimise allocation profiling for PHP >= 8.4 #3550

### Fixed
- Fixed bindgen compatibility with PHP 8.5.1+ on macOS #3583
- Fixed SystemSettings initialization #3579
- Fixed UB and simplify SystemSettings #3578
- Fixed crash in upload for DD_EXTERNAL_ENV #3576
- Fixed crash in ddtrace_get_profiling_context #3563
- Check long string before allocating #3561
- Fixed incompatibility with ext-grpc #3542
- Revert unsafe optimization in memory profiling #3541
- Cap dependency name length to copied bytes #3538

### Internal
- Pre-reserve function name buffer #3445
- Use cached heap in alloc_prof_orig_* functions #3547

## AppSec
### Added
- Reduce cardinality of helper.connection_* #3586
- Added fallback on http.endpoint for schema sampler #3557

### Fixed
- Use abstract namespace on linux #3525

### Internal
- Improvements for appsec libxml2 usage #3564
- Improve xml parsing in appsec #3558
