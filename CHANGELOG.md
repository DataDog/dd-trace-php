Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Changed
- Write sidecar logs when it runs in thread mode #4235
- `datadog-setup.php` now reports that macOS is not supported #4284 (thank you @staabm for the report!)

### Fixed
- Stop spawning a new sidecar while a PHP worker shuts down, which left orphaned sidecar processes #4164
- Correctly handle a thread-mode sidecar that takes over as the new master process #4270
- Fix sidecar telemetry lock inversions, premature sidecar idle shutdown and a hang on Windows when the sidecar pipe is full #4219
- Avoid a race between the SSI loader's background `dlclose()` and process exit #4229
- Crash reports now respect proxy environment variables, and the agent host/port takes priority over a UDS socket for error tracking uploads DataDog/libdatadog#2462, DataDog/libdatadog#2633
- Authenticate sidecar connections and shared memory (peer credential checks, trusted shared memory directory), fixing https://github.com/DataDog/dd-trace-php/security/advisories/GHSA-j3g8-6962-2xqq and https://github.com/DataDog/dd-trace-php/security/advisories/GHSA-fmrj-jm6v-j549 DataDog/libdatadog#2551

### Internal
- Update Rust dependencies to fix RustSec advisories; building from source now requires Rust 1.91.1 #4188
- Publish the OTel process context during MINIT #4213

## Tracer
### Added
- Support OTel sampling (`ot.th`/`ot.rv` tracestate) in distributed tracing #4177

### Changed
- Update the default query string obfuscation regex, fixing a security vulnerability, as described in https://github.com/DataDog/dd-trace-php/security/advisories/GHSA-49fg-59cp-2hqj #4282
- Improve stability of the tracer on macOS #4187
- Client-side stats obfuscation now follows the agent's full SQL obfuscation config DataDog/libdatadog#2535

### Fixed
- Fix crashes at shutdown: on PHP 7.0/7.1 ZTS #4185, in `atexit` after ddtrace was unloaded #4197, in the zend extension shutdown #4218, and in remote config shutdown #4220
- Fix rare use-after-frees in the in-process background sender, during request processing #4189 and at shutdown when the writer thread gets detached #4278 (thank you @alexandre-daubois!)
- Make the flush on SIGTERM/SIGINT safe against crashes in the allocator #4222
- Fix a crash when walking the stack after a generator was destroyed during an aborted call #4272
- Fix JIT blacklisting on PHP 8.5+ and for generator memory #4226, #4236
- Fix multi-hooking with dynamic payloads and a crash when subclassing `HookData` #4236
- Do not start the background writer when the tracer is disabled during MINIT #4233
- Avoid starvation of synchronous flushes with the in-process background sender #4224
- Fix sidecar connection from threads impersonating another user under Windows #4280
- Make Windows remote config notifications safe against unloading ddtrace #4219
- Evaluate the sampling decision once per trace chunk during serialization #4219
- Set `peer.service` sources in the Predis and PDO connect integrations #4285, #4286 (thank you @redpanda for the reports!)
- Report `http.status_code` for Guzzle requests that throw on non-2xx responses, and fix `DD_TRACE_HTTP_CLIENT_ERROR_STATUSES` handling #4287 (thank you @fbaumann-ph for the report!)
- Fix client-side stats: `http.endpoint` takes precedence over `http.route` again, and deeply nested SQL can no longer exhaust the stack during obfuscation DataDog/libdatadog#2582, DataDog/libdatadog#2441
- Fix a possible divide-by-zero panic in the shared rate limiter #4259

### Internal
- Add the `_dd.sdk.otlp_export` marker to trace chunks #4240

## Profiling
### Changed
- Speed up sample processing, reducing dropped samples under load #4194

### Fixed
- Fix several I/O profiling issues: `poll` readiness, `fread`/`fwrite` byte counts, RELRO protection after GOT patching, and loader bounds checks #4156
- Fix a wall-time crash after ext-grpc runs PHP code on a native thread; I/O profiling is disabled when ext-grpc is loaded #4198
- Report allocator-rounded allocation sizes and fix upscaling of samples with varying allocation sizes #4230
- Block signals before spawning profiler helper threads #4224

## AppSec
### Changed
- Update libddwaf to 2.1.0 and build it from source for PECL packages, fixing offline builds #4203 (thank you @remicollet for the report!)
- Update vendored libxml2 to 2.15.4 #4224

### Fixed
- Fix loading AppSec on musl ZTS builds (e.g. Alpine, FrankenPHP), and a crash on ZTS thread exit #4180
- Fix protocol failures caused by empty AppSec data #4216
