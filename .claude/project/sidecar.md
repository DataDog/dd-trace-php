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

## Connection model

Every PHP thread has its own connection, and processes one request at a
time. So the sidecar keeps all session and application state per connection
(`ConnectionSidecarHandler` in `service/sidecar_server.rs`); messages carry
no session, runtime or queue IDs:

- `set_connection_config` (identity + config) is the first message on a
  connection; various `set_*` methods update it.
- `set_application` sets the application of the current request at RINIT
  (and on service/env/version changes), and clears it at RSHUTDOWN. Data
  messages (telemetry, debugger, traces) apply to it.
- The client transport (`service/sender.rs`) mirrors this state: it skips
  unchanged values, and replays all of it onto the new connection after a
  reconnect (`adopt_state`). The C side configures a transport once and
  never replays anything.
- Closing a connection releases its state (remote config subscription,
  AppSec client, ...). Shared aggregators (trace flusher, telemetry workers
  per service/env, remote config fetchers, agent info, stats concentrators)
  stay server-global.

Traces, telemetry, remote config (via shared memory), DogStatsD,
crashtracking, live debugger, and appsec data all flow through the sidecar —
request threads never block on network I/O.

## Submit → upload

- Closed spans serialize in `tracer/span.c` → `ddog_TracesBytes`.
- At end-of-request `ddtrace_flush_tracer` (`tracer/auto_flush.c`) calls
  `ddog_send_traces_to_sidecar`, passing the batch over the
  `ddog_SidecarTransport` IPC (Unix socket / shared memory).
- Sidecar side: `TraceFlusher`
  (`libdatadog/datadog-sidecar/src/service/tracing/trace_flusher.rs`)
  enqueues incoming send-data, batches (by endpoint), and flushes on
  interval (~5s) or size threshold (~1MB), then uploads to the agent (or
  Datadog directly if agentless).
- Telemetry, remote config, DogStatsD, live debugger, and appsec enqueue
  separately and are multiplexed to the shared endpoint.
- Fork: the child drops the inherited transport, regenerates instance/runtime
  IDs (the session ID is preserved across fork), and reconnects
  (`datadog_sidecar_handle_fork` in `ext/sidecar.c`).

## Gotchas

- `DD_TRACE_SIDECAR_CONNECTION_MODE` = `auto` | `subprocess` | `thread`.
- Thread mode is **not** `pcntl_fork()`-safe — apps that fork must use
  subprocess mode.
- The transport type `ddog_SidecarTransport` is opaque, created in Rust.
- Reconnect happens automatically on sidecar crash.
- Connection modes are documented only in code comments in `ext/sidecar.c`
  — there is no `docs/SIDECAR_CONNECTION_MODES.md`.
- Config defaults live in `ext/configuration.h`.
