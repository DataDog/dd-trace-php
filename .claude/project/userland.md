# src/ — PHP userland

## What it is

High-level instrumentation in PHP: span/scope management, config,
propagation, and ~40 library/framework integrations. C hooks fire; userland
builds and tags spans.

## Key files & dirs

- `src/api/` — Contracts: `Tracer`/`Span`/`SpanContext`/`ScopeManager`;
  logging, sampling, tags.
- `src/DDTrace/Tracer.php` — main implementation.
- `src/DDTrace/{Span,SpanContext,Scope,ScopeManager}.php`.
- `src/DDTrace/Transport/Internal.php` — calls `\DDTrace\flush()` in C.
- `src/DDTrace/Propagators/` — W3C/B3.
- `src/DDTrace/Integrations/` — base + per-library integrations, some with
  `V2`/`V3` subdirs for library versions.
- `src/bridge/` — `_files_*` / `_generated_*` file-list pairs (api, tracer,
  opentelemetry, openfeature) loaded by `tracer/autoload_php_files.c`, which
  registers the autoloader.
- `src/dogstatsd/`.
- OpenTelemetry / OpenTracer / OpenFeature compat layers under `src/DDTrace/`.

## How it fits

`tracer/autoload_php_files.c` registers the autoloader and loads the bridge
file lists at startup (path from `DD_TRACE_SOURCES_PATH`,
`datadog.trace.sources_path`). Userland calls `\DDTrace\GlobalTracer::get()`
then `startSpan` / `startActiveSpan`. Integrations load only if
`ddtrace_config_integration_enabled($name)` (a C config check).

C hooks in [tracer/hook, tracer/integrations](tracer.md) fire; PHP
integrations respond by creating/tagging spans; `Tracer` collects and flushes
via C.

## Gotchas

- `DD_TRACE_SOURCES_PATH` (`datadog.trace.sources_path`) is set by the loader;
  if missing, autoload is silently skipped.
- `DD_AUTOLOAD_NO_COMPILE` toggles `_files_` vs `_generated_` file lists.
- Before the extension loads, spans are no-ops.
