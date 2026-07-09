# sidecar — Rust background service

## What it is

A Rust service (`libdatadog/datadog-sidecar`) run as a subprocess or thread,
offloading I/O off request threads: batches/compresses/uploads to the agent
and to Datadog. Survives request crashes and is shared across language
tracers.

## Key files & dirs

- PHP/C: `ext/sidecar.{c,h}` (see [ext.md](ext.md)).
- Rust FFI bridge: `components-rs/sidecar.{rs,h}`
  (`ddog_sidecar_connect_php` bridges C config → Rust; see
  [components.md](components.md)).
- Rust core: `libdatadog/datadog-sidecar/`,
  `libdatadog/datadog-sidecar-ffi/src/lib.rs`,
  `libdatadog/datadog-sidecar-macros/`.
- Submission points: `ext/telemetry.c`, `ext/remote_config.c`,
  `tracer/coms.c` / `tracer/span.c` (traces; see [tracer.md](tracer.md)),
  `tracer/live_debugger.c`, dogstatsd, crashtracking, [appsec](appsec.md).

## How it fits

MINIT records the master PID (thread mode starts the master listener).
Setup builds instance/runtime IDs and connects (`auto` mode tries subprocess,
falls back to thread). RINIT does a per-thread connection plus
service/env tags. Global shutdown flushes and drops the transport.
`handle_fork` drops the inherited transport and reconnects after
`pcntl_fork()`.

Traces, telemetry, remote config (via shared memory), DogStatsD,
crashtracking, live debugger, and appsec data all flow through the sidecar —
request threads never block on network I/O.

## Gotchas

- `DD_TRACE_SIDECAR_CONNECTION_MODE` = `auto` | `subprocess` | `thread`.
- Thread mode is **not** `pcntl_fork()`-safe — apps that fork must use
  subprocess mode.
- The transport type `ddog_SidecarTransport` is opaque, created in Rust.
- Reconnect happens automatically on sidecar crash.
- Connection modes are documented only in code comments in `ext/sidecar.c`
  — there is no `docs/SIDECAR_CONNECTION_MODES.md`.
- Config defaults live in `ext/configuration.h`.
