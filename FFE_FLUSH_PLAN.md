# FFE Exposure Flush — Implementation Plan

## Context

PR #3630 introduces FFE (Feature Flag Evaluation) in the PHP tracer. Review caught
that `ddog_ffe_flush_exposures` in [components-rs/ffe.rs:516](components-rs/ffe.rs)
has no production caller: the only invoker is the PHP userland function
`DDTrace\ffe_flush_exposures()` wired at [ext/ddtrace.c:3040](ext/ddtrace.c),
which itself has zero callers outside of tests. Exposures enqueue forever into
`EXPOSURE_STATE` and never reach the agent EVP proxy.

**Scope: NTS PHP builds only (v1 target, per PROJECT.md).** ZTS explicitly
deferred. Architecture is ZTS-compatible (`Mutex<ExposureState>` is thread-safe),
but ZTS CI validation is out of scope until a follow-up phase.

## Architecture decision (A+)

Evaluation stays in-process. Exposure state (dedup cache + batch buffer) stays
in-process. Sidecar owns async HTTP transport to the agent. Flush is triggered
by PHP request/module shutdown, not a background timer (PHP has no background
threads in either NTS or ZTS).

Rejected alternatives:
- **B (state in sidecar):** per-evaluation IPC on hot path. Latency regression
  that scales worst under ZTS (N threads × eval rate × IPC RTT).
- **C (sync HTTP in PHP):** libcurl on the request path. Blocks responses.

Rationale documented in session 2026-04-22 discussion.

### Topology

```
PHP process                            Sidecar process          Agent
───────────                            ───────────────          ─────
DDTrace_ffe_evaluate ──► FFE_STATE
                             │
                             └──► EXPOSURE_STATE (global Mutex)
                                        │
                      RSHUTDOWN/MSHUTDOWN flush
                                        │
                      ddog_ffe_flush_exposures → payload CharSlice
                                        │
                      ddog_sidecar_send_ffe_exposures(payload)
                                        │   (IPC)
                                        ▼
                                  sidecar_server ─► ffe_flusher
                                                        │ POST
                                                        ▼
                                              /evp_proxy/v2/api/v2/exposures
                                              X-Datadog-EVP-Subdomain:
                                                event-platform-intake
```

### Schema (confirmed across all 5 tracers)

Cross-tracer protocol research (2026-04-22):

| Tracer | Endpoint | Subdomain header | Interval | agent_info gate |
|---|---|---|---|---|
| dd-trace-go | `/evp_proxy/v2/api/v2/exposures` | `event-platform-intake` | 1s | none |
| dd-trace-rb | `/evp_proxy/v2/api/v2/exposures` | `event-platform-intake` | worker-driven | none |
| dd-trace-py | `/evp_proxy/v2/api/v2/exposures` | `event-platform-intake` | periodic writer | none |
| dd-trace-js | `/evp_proxy/v2/api/v2/exposures` | `event-platform-intake` | interval + buffer-fill | none |
| dd-trace-dotnet | `evp_proxy/v2/api/v2/exposures` | (EventPlatform helper) | 10s | none |

**No tracer gates exposure submission on `agent_info`.** PHP matches — POST
direct, log `debug` on non-2xx, no capability check.

- **Endpoint:** `POST /evp_proxy/v2/api/v2/exposures`
- **Subdomain header:** `X-Datadog-EVP-Subdomain: event-platform-intake`
- **Content-Type:** `application/json`
- **Timeout:** 5s
- **Dedup cache capacity:** 65536
- **Payload:**
  ```json
  {
    "context": {"service": "...", "version": "...", "env": "..."},
    "exposures": [
      {
        "timestamp": 1234567890,
        "flag": {"key": "..."},
        "allocation": {"key": "..."},
        "variant": {"key": "..."},
        "subject": {"id": "...", "attributes": {...}}
      }
    ]
  }
  ```

PHP's [ExposureWriter::buildEventJson](src/DDTrace/OpenFeature/ExposureWriter.php)
already emits per-event payloads matching this schema (verified
2026-04-22). Batch wrapper built by `ddog_ffe_flush_exposures`.

### ZTS / NTS coverage

| Concern | NTS | ZTS |
|---|---|---|
| `EXPOSURE_STATE` global Mutex | 1 thread, zero contention | N threads hit same mutex, microsecond lock |
| Drain semantics | whole buffer each RSHUTDOWN | first thread to RSHUTDOWN ships all threads' events |
| Service context | 1 global | 1 global (PHP service identity is process-level) |
| Transport | 1 sidecar transport | per-thread sidecar transport (commit `6b55c3ee5`) |
| Flush triggers | RSHUTDOWN + MSHUTDOWN | per-thread RSHUTDOWN + process MSHUTDOWN |

`FFE_STATE` (config) per-thread migration for ZTS is a separate follow-up
already flagged at [components-rs/ffe.rs:25-28](components-rs/ffe.rs).
Exposure state does not need per-thread split — it is aggregate telemetry,
not per-request-context data.

## Dedup key alignment

dd-trace-go uses `key=(flag_key, targeting_key) → value=(allocation_key, variant)`.
Current PHP impl at [components-rs/ffe.rs:475](components-rs/ffe.rs) uses
`key=flag\0alloc\0targeting → value=variant`.

Difference: PHP's version creates a new cache entry on every allocation change
for the same (flag, subject). Orphans old entries until LRU evicts, wastes cache
capacity on allocation churn.

Aligning to dd-trace-go: `add(key, value)` returns `true` when entry is new OR
value changed (allocation or variant); `false` when both match. Emits the new
exposure and updates the cache entry in-place.

## Task breakdown

### T1 — align dedup key shape
**File:** `components-rs/ffe.rs:356-498`
- Split `ExposureState` so dedup key = `(flag_key, targeting_key)`, value =
  `(allocation_key, variant_key)`.
- `ddog_ffe_enqueue_exposure`: return `false` only when both allocation and
  variant unchanged; update entry on change.

### T2 — `SidecarAction::FfeExposures` variant
**File:** `libdatadog/datadog-sidecar/src/service/mod.rs:78-83`
- Add `FfeExposures(String)` to the `SidecarAction` enum.
- Wire dispatch in `libdatadog/datadog-sidecar/src/service/sidecar_server.rs`
  `enqueue_actions` match arm → `ffe_flusher.submit(payload)`.

### T3 — sidecar `ffe_flusher` module
**Files:** `libdatadog/datadog-sidecar/src/service/ffe_flusher.rs` (new),
module registered in `service/mod.rs`.
- Mirror `trace_flusher.rs` shape: tokio task + unbounded channel, hyper
  client with 5s timeout.
- POST to `<agent_url>/evp_proxy/v2/api/v2/exposures` with
  `Content-Type: application/json` and
  `X-Datadog-EVP-Subdomain: event-platform-intake`.
- Log error on non-2xx (mirror dd-trace-go behaviour — no agent-version gate,
  no direct-intake fallback for v1).

### T4 — FFI wrapper in components-rs
**File:** `components-rs/ffe.rs` (append) or `components-rs/sidecar.rs`
- `ddog_sidecar_send_ffe_exposures(transport, instance_id, queue_id, CharSlice payload) -> MaybeError`
- Body: `blocking::enqueue_actions(transport, instance_id, queue_id,
  vec![SidecarAction::FfeExposures(payload.to_utf8_lossy().into_owned())])`

### T5 — RSHUTDOWN hook
**File:** `ext/sidecar.c::ddtrace_sidecar_rshutdown`
```c
ddog_CharSlice payload = ddog_ffe_flush_exposures();
if (payload.ptr != NULL && payload.len > 0) {
    ddog_sidecar_send_ffe_exposures(
        &DDTRACE_G(sidecar),
        ddtrace_sidecar_instance_id,
        &DDTRACE_G(sidecar_queue_id),
        payload);
    ddog_ffe_free_flush_result(payload);
}
```

### T6 — MSHUTDOWN hook
**File:** `ext/sidecar.c::ddtrace_sidecar_shutdown` (or nearest module shutdown)
- Same call pattern as T5. Catches any exposures enqueued after the final
  RSHUTDOWN of the process.

### T7 — doc comment rewrite
**File:** `components-rs/ffe.rs:504-506`
- Replace "In production, the sidecar's periodic flush loop calls this function"
  with:
  "Called from PHP RSHUTDOWN and MSHUTDOWN hooks. The returned payload is
  forwarded to the sidecar via `ddog_sidecar_send_ffe_exposures`, which POSTs
  it asynchronously to the agent's EVP proxy at `/evp_proxy/v2/api/v2/exposures`."

### T8 (deferred) — buffer-threshold flush
For long-running CLI / worker scripts. Flag-based: enqueue sets a "flush needed"
atomic when buffer passes threshold; RSHUTDOWN checks flag first. Avoids
transport plumbing into `ffe.rs`. Separate PR.

### T9 — fork handler resets exposure state in child
**File:** `ext/sidecar.c::ddtrace_sidecar_handle_fork`
- After existing fork logic, call `ddog_ffe_reset_exposure_state()` (exists at
  `components-rs/ffe.rs:597`).
- Prevents double-send of parent's pre-fork buffered events by clearing the
  child's inherited buffer. Dedup cache is also cleared — accepted per-tracer
  guidance (≤1 extra exposure per unique (flag, subject) per fork event;
  server-side dedup catches it).
- Parent keeps state → flushes at parent RSHUTDOWN. No parent-side data loss.

## Validation plan

Each validation step is a real command. `PASS` gate = exit code 0 and the
documented assertion holds.

### V1 — Unit: dedup key semantics (Rust)
**What it proves:** T1 dedup behaviour matches dd-trace-go.
**New test:** `components-rs/ffe.rs` `#[cfg(test)] mod tests`
**Run:**
```
cargo test -p ddtrace-php ffe::tests::dedup_key
```
**Assertion:** same (flag, targeting) with changed allocation OR variant
re-enqueues; with both unchanged returns `false`.

### V2 — Unit: batch payload schema (Rust)
**What it proves:** `ddog_ffe_flush_exposures` output is byte-identical to
dd-trace-go expectation when given the same inputs.
**New test:** `components-rs/ffe.rs` `#[cfg(test)] mod tests::flush_schema`
**Run:**
```
cargo test -p ddtrace-php ffe::tests::flush_schema
```
**Assertion:** parsed JSON contains `context.{service,env,version}` and
`exposures[]` each with `timestamp`, `flag.key`, `allocation.key`,
`variant.key`, `subject.id`, `subject.attributes`.

### V3 — Unit: sidecar flusher HTTP wire (Rust)
**What it proves:** T3 ffe_flusher sends correct method, path, headers, body.
**New test:** `libdatadog/datadog-sidecar/src/service/ffe_flusher.rs`
`#[cfg(test)] mod tests::posts_to_evp_proxy`
**Mechanism:** spawn `httpmock::MockServer` (already a dev-dep in
`libdatadog/datadog-sidecar/Cargo.toml`) on a loopback port; point
`agent_url` at it; submit one payload; verify the received request.
**Run:**
```
cargo test -p datadog-sidecar ffe_flusher::tests
```
**Assertion:**
- method == POST
- path == `/evp_proxy/v2/api/v2/exposures`
- header `x-datadog-evp-subdomain` == `event-platform-intake`
- body parses as the exposurePayload schema

### V4 — PHP: RSHUTDOWN triggers flush (phpt)
**What it proves:** T5 hook fires on request end and the FFI-level exposure
buffer is drained after RSHUTDOWN.
**New test:** `tests/ext/ffe/rshutdown_flush.phpt`
**Mechanism:** `.phpt` script that:
1. Calls `DDTrace\ffe_send_exposure(...)` to enqueue one event
2. Asserts `DDTrace\ffe_flush_exposures()` would return non-null (buffer warm)
3. Ends — `.phpt` SAPI drives RSHUTDOWN automatically
4. A second `.phpt` scenario in same file re-initializes, calls
   `ffe_flush_exposures()` immediately, asserts it returns null (buffer was
   drained by prior RSHUTDOWN)

**Why not PHPUnit + `sidecarCallable`:** the closure-injection mock
(`tests/OpenFeature/*Test.php`) validates the userland ExposureWriter layer
only. RSHUTDOWN is below userland — it fires in C and calls
`ddog_ffe_flush_exposures` directly, bypassing the injectable closure. `.phpt`
probe directly at the FFI boundary is the correct test surface.

**Run:**
```
make test_featureflags TESTS=tests/ext/ffe/rshutdown_flush.phpt
```
**Assertion:** second scenario's `ffe_flush_exposures()` returns null (proving
RSHUTDOWN of first scenario drained the buffer).

### V5 — PHP integration: round-trip to mock agent
**What it proves:** entire pipeline (PHP → FFI → sidecar → HTTP) works against
a locally-run mock agent.
**New test:** new `tests/OpenFeature/ExposureTransportTest.php` or a `.phpt`
under `tests/ext/ffe/`.
**Mechanism:** start a lightweight native `stream_socket_server` mock agent
on `127.0.0.1:0`; export `DD_TRACE_AGENT_URL=http://127.0.0.1:<port>` before
the test SAPI spawns the sidecar; evaluate a flag via
`DDTrace\ffe_send_exposure`; end request (RSHUTDOWN); poll mock agent log.
**Run:**
```
make test_featureflags
```
**Assertion:** mock agent log shows one POST to
`/evp_proxy/v2/api/v2/exposures` with `X-Datadog-EVP-Subdomain:
event-platform-intake`.

### V6 — PHP integration: dedup across requests
**What it proves:** LRU dedup survives across RSHUTDOWN for the same
(flag, subject).
**New test:** extends `tests/OpenFeature/CrossRequestDedupTest.php`.
**Mechanism:** evaluate the same flag with the same subject in two consecutive
requests separated by RSHUTDOWN; assert the mock agent received only one
exposure.
**Run:** `make test_featureflags`.
**Assertion:** `mock.requestCount() == 1`.

### V7 — (removed — ZTS out of scope for v1)

### V8 — Lint / format guards (non-regression)
**Run:**
```
cargo fmt --check
cargo clippy --workspace --all-targets -- -D warnings
php -l ext/sidecar.c   # not applicable; use existing C lint path
make format_c_check
```
**Assertion:** all green. No new warnings introduced.

### V9 — Fork dedup smoke test (PHP integration)
**What it proves:** T9 fork hook resets child state; bounded duplication per
fork event, no double-send of pre-fork parent buffer.
**New test:** `tests/ext/ffe/fork_dedup.phpt` (pcntl pattern already used in
`tests/ext/pcntl/*.phpt` — CI has pcntl enabled).
**Mechanism:**
1. Parent evaluates flag A with subject X → parent buffers 1 exposure.
2. Parent calls `pcntl_fork()`.
3. Child evaluates flag A with subject X → dedup cache reset by T9, child
   buffers 1 exposure.
4. Parent evaluates flag A with subject X again → dedup cache still warm in
   parent, buffers 0 exposures.
5. Child RSHUTDOWN → mock agent receives 1 exposure (from child).
6. Parent RSHUTDOWN → mock agent receives 1 exposure (from parent's pre-fork
   enqueue).
**Run:**
```
make test_featureflags TESTS=tests/ext/ffe/fork_dedup.phpt
```
**Assertion:** `mock.requestCount() == 2` (one parent + one child). Not 1
(would mean child lost its event), not 3+ (would mean no dedup or parent
double-sent).

## Ordering

```
T1 ─► T2 ─► T3 ─► T4 ─► T5 ─► V4
                          │    │
                          └──► V5 ─► V6
                          │
                          ├─► T6
                          ├─► T7
                          └─► T9 ─► V9
V1, V2 in parallel with T1–T4.
V3 gates after T3.
V8 runs in CI per commit.
```

## Out of scope

- **ZTS support entirely** — v1 targets NTS only (per PROJECT.md). Architecture
  is ZTS-compatible (`Mutex<ExposureState>` is thread-safe) so future ZTS
  enablement is a drop-in. No ZTS test job added in this PR.
- `FFE_STATE` per-thread migration for ZTS (separate phase, see ffe.rs:25-28).
- Buffer-threshold flush trigger for long-lived CLI / worker scripts (T8,
  separate PR).
- Agent-version gating / direct-intake fallback (not done by dd-trace-go for v1).
- Telemetry / self-metrics on flush success/failure rates (follow-up).

## Resolved decisions

1. **`agent_info` gate — SKIP.** Protocol research across Go, Ruby, Python, JS,
   .NET confirmed zero tracers gate exposure submission on
   `evp_proxy_allowed_headers` or any other agent_info capability. All POST
   direct and log on non-2xx. PHP matches.
2. **Fork handling — child resets state (T9).** Parent keeps its buffer for
   its own RSHUTDOWN flush. Child clears dedup cache + buffer via
   `ddog_ffe_reset_exposure_state()` in `ddtrace_sidecar_handle_fork`.
   Accepts bounded duplication: ≤ 1 extra exposure per unique (flag, subject)
   per fork event. Server-side dedup catches residual dup.
