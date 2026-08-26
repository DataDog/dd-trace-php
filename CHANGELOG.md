Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Fixed
- Repair the macOS crashtracking sidecar connector #4069
- Avoid leaking a patched `module_entry` across SSI loader shutdown, which could crash on reload #4087
- Fix `vsnprintf` off-by-one buffer sizing across appsec/logging/telemetry call sites #4115
- Harden crash-report backtrace collection from a multi-threaded process via ptrace DataDog/libdatadog#2216
- Fix parent-death-signal handling in the sidecar/loader child-process bootstrap DataDog/libdatadog#2348

### Internal
- Crashtracker now shares the sidecar's socket instead of holding a dedicated one, reducing per-process file descriptor usage DataDog/libdatadog#2179
- Fix the debug build variant of the SSI loader on PHP 8.4 #4089

## Tracer
### Added
- Support `DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT` (`continue`/`restart`/`ignore`) to control how upstream distributed-trace context is extracted, matching other Datadog tracers #3997
- Add `DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS` to bound Dynamic Instrumentation capture time #4003
- Add experimental Feature Flag Events (FFE) span enrichment, opt-in via `DD_EXPERIMENTAL_FLAGGING_PROVIDER_SPAN_ENRICHMENT_ENABLED` (default off) #3996
- Publish standard OTel process and thread contexts from the tracer, consumed by the profiler for runtime identity and effective service metadata #4077

### Changed
- Propagate the agent-configured timeout to the sidecar instead of using a fixed value #4025

### Fixed
- Scan the agent's full peer-tag key list for client-side stats instead of truncating it, which had started silently dropping tags like `out.host`/`peer.hostname`/`peer.service`/`server.address` #4102
- Preserve the complete W3C trace-flags byte when converting a Datadog span into an OpenTelemetry span context, fixing sampled+random-id contexts being treated as unsampled #4112
- Fix health metrics over UDS #4116

### Internal
- Report OTLP export status (traces/metrics/logs) in the startup log #4056
- Update libdatadog and fix background-sender telemetry errors/flakiness #4073, DataDog/libdatadog#2277

## Profiling
### Added
- Add build compatibility with PHP 8.6 #4084

### Changed
- Avoid an FFI call on the allocation hot path on NTS builds #4068
- Remove indirect allocator forwarding by specializing malloc/free/realloc callbacks at RINIT #4070
- Speed up `interrupt_count` checks by using a relaxed atomic outside of `REQUEST_LOCALS` #4074
- Reuse a single cached TSRM pointer for profiler and executor globals in the allocation hook (PHP ≤8.3) #4072

## AppSec
### Added
- Support the `server.response.body.raw` WAF address for raw (unparsed) HTTP response body inspection #4055

### Fixed
- Ensure `_dd.apm.enabled:0` is present on every span so it is reliably encountered during APM-standalone billing processing #4054

### Internal
- Report `DD_APPSEC_AGENTIC_ONBOARDING` in configuration telemetry #4053
