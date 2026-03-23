Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Changed
- Cache system getenv calls for improved request initialization performance #3670

### Fixed
- Fix zombie creation in loader #3683

### Internal
- Changed defaults of configurations and fixed DD_TRACE_HTTP_CLIENT_ERROR_STATUSES #3621, #3677

## Tracer
### Added
- Collect framework endpoints for Symfony, Laravel, and WordPress #3548
- Add sidecar thread mode as fallback connection for restricted environments #3573
- Add process_tags #3580, #3582, #3627, #3658, #3706, #3709
- Add `_dd.p.ksr` propagated tag for Knuth sampling rate #3701
- Add container tags support for DBM correlation #3708

### Changed
- Optimize Symfony http.route caching with path map approach #3676
- Change the sidecar communication protocol #3695, DataDog/libdatadog#1742

### Fixed
- Poll for new remote config after unblocking SIGVTALRM #3717
- Fix crash during shutdown in FrankenPHP #3662
- Fix possible race condition leading to crash on sidecar reconnect in ZTS mode #3655
- Fix possible crash in end hook of traced closure #3624
- Fix hook is_internal being backwards #3625
- Enforce span limit in curl_multi_exec and DDTrace\start_span code paths #3691
- Prevent dangling tracked_streams #3689
- Fix debugger ephemerals handling for nested log probes #3685
- Block sidecar notification signal during sleep to prevent premature wakeup #3656
- Fix sidecar permission denied with IIS AppPools DataDog/libdatadog#1776
- Cleanup limiters on sidecar shutdown DataDog/libdatadog#1659
- Fix function and type name ordering in debugger DataDog/libdatadog#1715

## Profiler
### Added
- Add I/O profiling support for macOS #3648
- Add process_tags to profiler uploader #3609
- Improve time sample accuracy by also gathering during allocation samples #3559

### Fixed
- Store and restore errno in I/O profiling wrappers #3654

### Internal
- Add internal metrics for profiling overhead #3616
- Avoid copy of function name when already UTF-8 encoded #3700
- Use libdd-profiling's ThinStr for function names #3631
- Shrink maximum file and function name length to 16,383 characters #3712
- Refactor ErrnoBackup::new is safe #3659
- Remove once_cell as a dependency #3607

## AppSec
### Added
- Support parsing partial JSON #3680
- Enable LLM event observability for OpenAI PHP client #3664

### Changed
- Revert DD_APPSEC_ENABLED to runtime config #3598

### Fixed
- Send response headers on meta even without event #3653

### Internal
- Rewrite AppSec helper in Rust #3581
- Submit worker count and route AppSec metrics directly to sidecar #3530
- Upgrade libxml2 #3690
