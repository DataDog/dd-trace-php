Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

A more elaborate summary of the breaking changes can be found in our upgrading guide [UPGRADE-2.0.md](https://github.com/DataDog/dd-trace-php/blob/ddtrace-2.0.0/UPGRADE-2.0.md).

## Breaking changes
- Ship the tracer, the profiler and AppSec as a single `ddtrace.so` extension; `datadog-profiling.so` and `ddappsec.so` are no longer shipped #4179, #4369
  - Loading an old `ddappsec.so` together with `ddtrace.so` is a fatal startup error. `datadog-setup.php` deletes a leftover `ddappsec.so` and comments out `datadog-profiling.so` entries.
  - PECL builds include profiling and AppSec only when configured with `--enable-ddtrace-profiling` and `--enable-ddtrace-appsec`.
- Profiling is now opt-in: `DD_PROFILING_ENABLED` defaults to `false`. Use `datadog-setup.php --enable-profiling` or set `datadog.profiling.enabled=On`. When upgrading an installation that loaded `datadog-profiling.so`, `datadog-setup.php` keeps profiling enabled #4179
- Use the sidecar as the default trace sender on all PHP versions (previously PHP 8.3+ and Windows only); `DD_TRACE_SIDECAR_TRACE_SENDER=0` selects the in-process sender #4453
- Enable client-side stats computation by default; disable it with `DD_TRACE_STATS_COMPUTATION_ENABLED=false`. Stats are computed only with the sidecar trace sender #4498, #4510
- Change how span data is stored and serialized #4453
  - `SpanData::$meta` and `SpanData::$metrics` are deprecated views onto the new `SpanData::$attributes`.
  - Legacy `DDTrace\Span`: `setTag($key, null)` and `setMetric($key, null)` remove the key, boolean tags become `"true"`/`"false"` (previously `"1"`/`""`), and `getAllTags()` also returns `service.name`, `operation.name` and `span.type`.
  - A non-empty `$tags` in `#[DDTrace\Trace]` still works but raises `E_DEPRECATED`. Use `$attributes` instead.
  - `SpanLink` and `SpanEvent` no longer implement `JsonSerializable`.
- Remove trace analytics: the `DD_TRACE_ANALYTICS_ENABLED`, `DD_TRACE_<INTEGRATION>_ANALYTICS_ENABLED` and `DD_TRACE_<INTEGRATION>_ANALYTICS_SAMPLE_RATE` settings (and their `DD_<INTEGRATION>_ANALYTICS_*` aliases), `DDTrace\Config\integration_analytics_enabled()` and `DDTrace\Config\integration_analytics_sample_rate()`. The `analytics.event` tag is no longer mapped to `_dd1.sr.eausr` #4453
- Remove `DD_TRACE_WARN_LEGACY_DD_TRACE` and the internal functions `dd_trace_serialize_msgpack()`, `dd_trace_send_traces_via_thread()`, `dd_trace_buffer_span()`, `dd_trace_coms_trigger_writer_flush()`, `dd_trace_reset()` and `dd_trace_noop()` #4453
- Deprecate (with `E_DEPRECATED`) `DDTrace\trace_id()`, `DDTrace\current_context()`, `ddtrace_config_app_name()`, `ddtrace_config_distributed_tracing_enabled()`, `ddtrace_config_trace_enabled()`, `dd_trace_set_trace_id()`, `dd_trace_compile_time_microseconds()`, `dd_trace_function()` and `dd_trace_method()` #4453

## All products
### Changed
- Reduce sidecar memory usage: an idle sidecar thread no longer keeps a 4 MiB IPC receive buffer resident DataDog/libdatadog#2743
- Asynchronously watch the sidecar for being alive #4511

### Fixed
- Fix heap corruption in a newly forked worker whose first request fails while PHP parses POST data, if PHP logged a line during module startup (e.g. the SSI loader with `DD_TRACE_DEBUG`) #4468
- Fix a race between writers and a forced drain of the sidecar's shared-memory buffers, e.g. at shutdown DataDog/libdatadog#2885
- Fix a file descriptor leak in the sidecar's remote config handling, and stop remote config indexes from pointing at files that were already removed DataDog/libdatadog#2880
- Fix sidecar connections on macOS closing on messages within 16 bytes of the socket buffer limit DataDog/libdatadog#2743

### Internal
- Update and deduplicate Rust dependencies #4457, #4458, #4459

## Tracer
### Added
- Send traces using the V1 efficient trace payload protocol to agents that support it (7.79+), falling back to v0.4 otherwise. `DD_TRACE_AGENT_PROTOCOL_VERSION=0.4` always sends v0.4 #4453
- Fetch feature flag configuration from the Datadog CDN without an agent, enabled with `DD_FEATURE_FLAGS_CONFIGURATION_SOURCE=agentless`. Exposures and evaluation counts then go straight to the intake, using `DD_API_KEY` and `DD_SITE` #4312, #4489
- Enable and disable Data Streams Monitoring at runtime via Remote Config #4472

### Changed
- Keep the PHP type of span, span link and span event attribute values: numbers and booleans are sent typed, and arrays are nested. `http.status_code`, `env`, `version` and IDs stay strings. Spans get a typed `SpanData::$attributes` store and the `$env`, `$version`, `$component` and `$spanKind` (`DDTrace\SpanKind`) properties, and `#[DDTrace\Trace]` accepts `$attributes`, `$kind` and `$component` #4453

### Fixed
- Honour `DD_SITE` for agentless trace submission (`DD_TRACE_AGENTLESS`), which always sent traces to `datadoghq.com` #4501
- Attribute feature flag evaluation counts to the application's service, env and version DataDog/libdatadog#2716

### Internal
- Stop sending `app-started` and `app-closing` telemetry events from forked children DataDog/libdatadog#2837

## Profiling
### Internal
- Move PHP-independent Rust code, including profile aggregation and export, into the shared SSI library `libdatadog_php.so` #4455

## AppSec
### Fixed
- Keep the trace of a request blocked by AppSec during request startup when `DD_TRACE_AUTO_FLUSH_ENABLED=1` is set on a web SAPI #4445
- Recover when a startup bailout skipped the previous request's shutdown. This could release freed memory or leave the next request unprotected #4465
- Apply the tracer's query string filtering and obfuscation to the `http.url` tag set by AppSec #4467

### Internal
- Remove AppSec dead code and tracer logic that was duplicated in AppSec #4417, #4467
