# tracer/ — tracer-specific C core

## What it is

The instrumentation engine which creates and
manages span lifecycles, sampling, metadata, msgpack serialization, and sends
traces (via the sidecar or the legacy in-process background sender).

## Key files & dirs

- `tracer/ddtrace.c` — tracer lifecycle hooks (`ddtrace_startup`/rinit/…)
  invoked from `ext/datadog.c`; routes sidecar vs in-process sender.
- `tracer/span.c` — span alloc/close stacks, ring buffer.
- `tracer/serializer.c` — PHP spans → libdatadog spans (msgpack in libdatadog).
- `tracer/auto_flush.c` — flush orchestration.
- `tracer/coms.c` + `tracer/comms_php.c` — legacy in-process background
  sender (Linux-only).
- `tracer/hook/` — `uhook*.c` userland hooks (`trace_function`/
  `trace_method`, sandboxed).
- `tracer/integrations/` — 40+ framework hooks.
- `tracer/priority_sampling/`, `tracer/limiter/` (token-bucket on closed
  spans), `tracer/tracer_tag_propagation/` (W3C + Datadog headers).
- `tracer/distributed_tracing_headers.c`, `tracer/code_origins.c`,
  `tracer/asm_event.c` (appsec events on spans), `tracer/collect_backtrace.c`,
  `tracer/endpoint_guessing.c`, `tracer/inferred_proxy_headers.c`,
  `tracer/live_debugger.c`, `tracer/dogstatsd_client.c`.

## How it fits

Startup registers the ZAI interceptor and the profiling symbol. RINIT reads
distributed headers and optionally creates a root span. A hook on function
entry allocates a span; on return it closes the span and applies sampling.
Auto-flush triggers when the open-span threshold is hit or on RSHUTDOWN:
serialize → sidecar or background sender.

Sits above [ext/](ext.md) infra and ZAI hooks (see
[components.md](components.md)); consumes libdatadog Rust. The sender default
is version-gated (`DD_SIDECAR_TRACE_SENDER_DEFAULT`, `tracer/configuration.h`):
the sidecar (see [sidecar.md](sidecar.md)) on PHP 8.3+/Windows, the in-process
`coms.c` sender on PHP 7.0–8.2 (coms.c isn't built on Windows); either is
overridable. [PHP userland](userland.md) (`src/DDTrace`) wraps these hooks as
objects.

## Data flow

Span→upload path, in order:

- RINIT (`tracer/ddtrace.c`): init per-request span stacks.
- `tracer/distributed_tracing_headers.c`: extract trace context from headers.
- `tracer/span.c`: create root span (inherit trace/parent IDs if enabled).
- `tracer/hook/uhook.c`: on function entry a ZAI hook fires → allocate span.
- `tracer/span.c`: on return → close span.
- `tracer/priority_sampling/`: sampling decided at close.
- `tracer/serializer.c`: PHP spans → libdatadog spans (msgpack in libdatadog).
- `tracer/auto_flush.c`: flush on threshold or RSHUTDOWN, then handoff →
  `ext/sidecar.c` (PHP 8.3+/Windows) or `tracer/coms.c` (PHP ≤8.2) → agent.

See [../../architecture.md](../../architecture.md) for the background sender
design.

## Gotchas

- Per-request span stacks, no locks; PHP 8.1+ fibers get separate span
  stacks.
- Sampling is decided at span close time, not open time.
- Userland hooks are sandboxed (exceptions in hook code don't crash the
  request).
- The root span closes last.
