# ext/ — extension infra shared across products

## What it is

Runtime bedrock shared by tracer, profiler, and appsec: config, sidecar IPC,
telemetry, remote config, signal handlers, logging, agent connectivity.
Tracer-specific C was split out into [tracer/](tracer.md) (#3912); stray
`ext/hook/`, `ext/integrations/` etc. are build artifacts, not sources.

## Key files & dirs

- `ext/datadog.c` — module entry (MINIT/RINIT/RSHUTDOWN/MSHUTDOWN).
- `ext/configuration.{c,h}` — shared/infra x-macro INI/env table (tracer-
  specific config is in `tracer/configuration.h`).
- `ext/sidecar.{c,h}` — IPC to the sidecar (see [sidecar.md](sidecar.md)).
- `ext/telemetry.{c,h}`, `ext/remote_config.{c,h}` — sidecar-backed features.
- `ext/logging.{c,h}` — signal-safe logging for the background sender.
- `ext/signals.{c,h}` — SIGTERM/INT/CHLD, `pcntl_fork` interception.
- `ext/endpoints.c` — agent/dogstatsd URL resolution.
- `ext/startup_logging.{c,h}` — first-RINIT diagnostics.
- `ext/process_tags.{c,h}`, `ext/agent_info.c`, `ext/git.c`.
- `ext/otel_config.{c,h}` — `OTEL_*` → `DD_*` bridging.
- `ext/crashtracking_windows.c` — Windows only; Unix crashtracking lives in
  libdatadog Rust.

## How it fits

MINIT: logging init first, then config, `zend_extension` registration,
sidecar, remote_config, signals (tracer pre/early/late phases interleave).

RINIT: remote_config → one-time `dd_rinit_once` (process tags, signals,
startup diagnostics, tracer first-rinit) → agent_info → sidecar → tracer.

RSHUTDOWN: remote_config → tracer → sidecar (finalize) → telemetry →
sidecar (rshutdown) → git.

MSHUTDOWN is **not** a reverse of MINIT — notably the sidecar shuts down
late (after config is freed). Order: tracer → remote_config → signals → log
→ config → sidecar → process_tags.

`ext/configuration.h` holds the shared/infra x-macro config table (tracer-
specific config lives in `tracer/configuration.h`). ext/ exposes the
sidecar/telemetry/remote_config public API consumed by [tracer/](tracer.md),
[appsec/](appsec.md), and [profiling/](profiling.md).

## Gotchas

- Config is x-macro-driven: add a macro + parser, not ad hoc code.
- `DD_TRACE_SIDECAR_CONNECTION_MODE`: `subprocess` is fork-safe, `thread` is
  not — see [sidecar.md](sidecar.md).
- `PHP_VERSION_ID` gating throughout; prefer ZAI (see
  [components.md](components.md)) for anything non-trivial.
- Signal handlers use a best-effort sidecar pointer (may be null/stale).
- Telemetry redacts repo paths before upload.
