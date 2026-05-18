Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## Tracer
### Changed
- Restrict the accepted amount of extracted tags and baggage #3854

### Fixed
- Fix parentId refcount underflow #3851
- Fix SpanStack active reference corruption #3853
- Retry FFI telemetry batches when session config not yet available DataDog/libdatadog#1929
- Fallback to ftruncate if fallocate gets EPERM DataDog/libdatadog#1938
