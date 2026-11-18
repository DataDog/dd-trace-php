# Upgrade to dd-trace-php 2.0

**Important Notice:** Upgrading `dd-trace-php` from version 1.x to 2.0 involves several breaking changes. Please review the following changes carefully before proceeding with the upgrade.

Version 2.0 ships the tracer, the profiler and appsec (Application Security) as a single extension. It also moves trace submission to the sidecar and the V1 trace payload protocol, and enables client-side stats by default. It removes trace analytics and deprecates several legacy functions.

The supported PHP versions are unchanged: PHP 7.0 and newer (profiling requires PHP 7.1 or newer).

## Changes Summary

- [Single `ddtrace.so` extension](#single-ddtraceso-extension)
  - [AppSec](#appsec)
  - [Profiling](#profiling)
  - [PECL builds](#pecl-builds)
- [Trace submission](#trace-submission)
  - [Sidecar trace sender](#sidecar-trace-sender)
  - [V1 trace payloads](#v1-trace-payloads)
  - [Client-side stats](#client-side-stats)
- [Span data](#span-data)
- [Trace analytics removal](#trace-analytics-removal)
- [Removed APIs and Configuration Keys](#removed-apis-and-configuration-keys)
- [Deprecated APIs](#deprecated-apis)

## Migration Guide

To ensure a smooth transition to version 2.0, follow these migration steps:

- **Remove standalone extensions**: Make sure there is no `extension=ddappsec.so` and `extension=datadog-profiling.so` in your INI files anymore. `datadog-setup.php` does this for you.
- **Enable profiling explicitly**: If you configure the extension manually rather than with `datadog-setup.php`, set `DD_PROFILING_ENABLED=true` (or `datadog.profiling.enabled=On`) to keep profiling.
- **Drop trace analytics settings**: Remove `DD_TRACE_ANALYTICS_ENABLED` and the `DD_TRACE_<INTEGRATION>_ANALYTICS_*` settings and their API calls.
- **Replace deprecated APIs**: Replace the functions listed under [Deprecated APIs](#deprecated-apis). Migrate from `SpanData::$meta` and `SpanData::$metrics` to `SpanData::$attributes`.
- **Review monitors on span tags**: Attribute values now keep their PHP type, and some legacy `DDTrace\Span` tag conversions changed.
- **Test thoroughly**: Test your application thoroughly after the upgrade to ensure that all functionalities are working as expected.

**Note**: Enable debug logs (`DD_TRACE_DEBUG=1`) and show `E_DEPRECATED` messages to find uses of deprecated APIs before migrating.

---

## Single `ddtrace.so` extension

The profiler and AppSec are now part of `ddtrace.so`. The separate `datadog-profiling.so` and `ddappsec.so` extensions are no longer shipped.

### AppSec

Loading an old `ddappsec.so` alongside `ddtrace.so` is a fatal startup error. When upgrading, `datadog-setup.php` deletes a leftover `ddappsec.so` from the extension directory.

Enabling AppSec is unchanged: use `DD_APPSEC_ENABLED`, or `datadog-setup.php --enable-appsec`.

### Profiling

| Configuration Option   | 1.x                                    | 2.0     |
|------------------------|----------------------------------------|---------|
| `DD_PROFILING_ENABLED` | `true` (when the profiler is loaded)   | `false` |

Loading the profiler used to enable it. As it is now part of every `ddtrace.so`, profiling is opt-in instead:

- `datadog-setup.php --enable-profiling` sets `datadog.profiling.enabled = On`.
- When upgrading an installation that loaded `datadog-profiling.so`, `datadog-setup.php` comments out that line and sets `datadog.profiling.enabled = On` to keep profiling enabled, unless the INI file already sets `datadog.profiling.enabled`.
- Manually managed configurations must set `DD_PROFILING_ENABLED=true`.

## Trace submission

### Sidecar trace sender

| Configuration Option            | 1.x                               | 2.0    |
|---------------------------------|-----------------------------------|--------|
| `DD_TRACE_SIDECAR_TRACE_SENDER` | `true` on PHP 8.3+ and Windows, `false` otherwise | `true` |

The sidecar now sends traces on all PHP versions. Set `DD_TRACE_SIDECAR_TRACE_SENDER=0` to keep the in-process sender (not available on Windows).

### V1 trace payloads

The sidecar sends traces using the V1 efficient trace payload protocol (`/v1.0/traces`) when the agent advertises it (agent 7.79 or newer), and falls back to v0.4 otherwise. The in-process sender always uses v0.4.

| Configuration Option              | Values                              | Default |
|-----------------------------------|-------------------------------------|---------|
| `DD_TRACE_AGENT_PROTOCOL_VERSION` | `1.0` (V1 when supported), `0.4`    | `1.0`   |

### Client-side stats

| Configuration Option                 | 1.x     | 2.0    |
|--------------------------------------|---------|--------|
| `DD_TRACE_STATS_COMPUTATION_ENABLED` | `false` | `true` |

Trace metrics are now computed by the tracer rather than by the agent. Stats are only computed with the sidecar trace sender. Set `DD_TRACE_STATS_COMPUTATION_ENABLED=false` to let the agent compute them.

## Span data

Spans now store their data in a single typed `SpanData::$attributes` array. Attribute values of spans, span links and span events keep their PHP type: numbers and booleans are sent typed, and arrays are nested. `http.status_code`, `env`, `version` and IDs stay strings.

New span properties: `$env`, `$version`, `$component` and `$spanKind` (`DDTrace\SpanKind`). `#[DDTrace\Trace]` accepts `$attributes`, `$kind` and `$component`.

Breaking changes:

- `SpanData::$meta` and `SpanData::$metrics` are deprecated views onto `$attributes`. Reading `$meta` lists every property-backed key that is set. Writing `service.name`, `resource.name`, `operation.name`, `span.type`, `env`, `version`, `component`, `span.kind` or `error.ignored` through `$meta` sets the matching span property.
- A non-empty `$tags` argument of `#[DDTrace\Trace]` still applies, but raises `E_DEPRECATED`. Use `$attributes` instead.
- Web root spans default `component` to `web`. Frameworks still override it, and CLI root spans get no default.
- The hostname (`DD_TRACE_REPORT_HOSTNAME`) is sent at payload level. The root span no longer has the `_dd.hostname` (`Tag::HOSTNAME`) tag; v0.4 payloads still carry it.
- `SpanLink` and `SpanEvent` no longer implement `JsonSerializable`.
- `dd_trace_serialize_closed_spans()` returns the V1 payload view (payload fields, `attributes` and `chunks`) instead of a flat list of spans.

Legacy `DDTrace\Span` API (OpenTracing compatible):

- `setTag($key, null)` and `setMetric($key, null)` now remove the key.
- Boolean tag values are now `"true"` / `"false"` (previously `"1"` / `""`).
- `getAllTags()` now also returns `service.name`, `operation.name` and `span.type`.
- An object passed to `log()` as the message is now sent as a map of its public properties, instead of being cast to a string.

## Trace analytics removal

Trace analytics (App Analytics) is removed. The `analytics.event` tag is no longer mapped to `_dd1.sr.eausr`. `Tag::ANALYTICS_KEY` and `TraceAnalyticsProcessor::normalizeAnalyticsValue()` remain as deprecated no-ops.

Use [ingestion controls](https://docs.datadoghq.com/tracing/trace_pipeline/ingestion_controls/) and [retention filters](https://docs.datadoghq.com/tracing/trace_pipeline/trace_retention/) instead.

## Removed APIs and Configuration Keys

### API Changes

| 1.x                                                  | 2.0 Migration |
|------------------------------------------------------|---------------|
| `DDTrace\Config\integration_analytics_enabled()`     | Removed       |
| `DDTrace\Config\integration_analytics_sample_rate()` | Removed       |

### Configuration Changes

| 1.x                                                                  | 2.0 Migration |
|----------------------------------------------------------------------|---------------|
| `DD_TRACE_ANALYTICS_ENABLED`                                         | Removed       |
| `DD_TRACE_<INTEGRATION>_ANALYTICS_ENABLED`, `DD_<INTEGRATION>_ANALYTICS_ENABLED`         | Removed |
| `DD_TRACE_<INTEGRATION>_ANALYTICS_SAMPLE_RATE`, `DD_<INTEGRATION>_ANALYTICS_SAMPLE_RATE` | Removed |
| `DD_TRACE_WARN_LEGACY_DD_TRACE`                                      | Removed       |

## Deprecated APIs

These functions still work, but raise `E_DEPRECATED` and will be removed in a future release:

| Function                                       | Replacement                                                                       |
|------------------------------------------------|-----------------------------------------------------------------------------------|
| `DDTrace\trace_id()`                           | `DDTrace\root_span()->traceId` (hex) or `DDTrace\logs_correlation_trace_id()`     |
| `DDTrace\current_context()`                    | The `DDTrace\root_span()` properties or `DDTrace\generate_distributed_tracing_headers()` |
| `ddtrace_config_app_name()`                    | `dd_trace_env_config('DD_SERVICE')`                                               |
| `ddtrace_config_distributed_tracing_enabled()` | `dd_trace_env_config('DD_DISTRIBUTED_TRACING')`                                   |
| `ddtrace_config_trace_enabled()`               | `dd_trace_env_config('DD_TRACE_ENABLED')`                                         |
| `dd_trace_set_trace_id()`                      | `DDTrace\set_distributed_tracing_context()`                                       |
| `dd_trace_compile_time_microseconds()`         | None                                                                              |
| `dd_trace_function()`                          | `DDTrace\trace_function()`                                                        |
| `dd_trace_method()`                            | `DDTrace\trace_method()`                                                          |

---

## Feedback and Support

If you encounter any issues during the upgrade process or have suggestions for improvement, please don't hesitate to reach out to the Datadog team for assistance. Your feedback is valuable in helping us improve the `dd-trace-php` library.

- [Datadog Documentation](https://docs.datadoghq.com/)
- [Datadog Support](https://www.datadoghq.com/support/)
- [GitHub Issues](https://github.com/DataDog/dd-trace-php/issues)
