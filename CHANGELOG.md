Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Internal
- bump tracing-core from 0.1.33 to 0.1.35 #3516

## Tracer
### Internal
- Const-ify some logging thread-local variables #3513
### Fixed
- Avoid curl's `getenv` calls #3528
- `code_origin_for_spans_enabled` naming inconsistency #3494
- Add `NULL` guard clause in sidecar reconnect callback #3499

## Profiler
### Added
- Detect parallel threads #3515
### Changed
- Speedup hot path in allocator #3505
### Fixed
- Fixed asserting length of INI #3508

## AppSec
### Added
- Minify blocking json message #3502
- Add Custom Data Classification #3524
- Add metrics for extension connections #3527
### Fixed
- Amend string on request abort #3506
- Fix accessing to incorrectly hardcoded `$_GET` #3501
- Amend issue where `security_response_id` is being release before displaying it #3493
- AppSec helper: add send timeouts #3518
- Minor fixes and improvements to file descriptor reclamation #3526
- LaravelIntegration: be more defensive #3503
- Fix `duration_ext` metric #3507
- Fix segfault iterating mapping #3517
- Fix double end hook run/segfault when blocking in PHP 7.x #3490
- Fix `_iovec_writer_flush` and enforce limits on `$_POST` #3495
- Clear `client_ip` on `request_init` #3496
