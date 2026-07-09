# Project knowledge

The Datadog PHP tracer (`ddtrace`): a PHP extension bringing APM, distributed
tracing, profiling, and application security to PHP. Multi-language:

- **C** extension (`ext/`, `tracer/`) — low-level hooks and runtime glue.
- **Rust** (`components-rs/`, `libdatadog/` submodule, `profiling/`, appsec
  helper) — shared cross-tracer functionality, sidecar, crashtracker.
- **PHP** userland (`src/`) — high-level instrumentation.

User docs: <https://docs.datadoghq.com/tracing/languages/php/>.
For build/test/CI, see the pointers below — do not duplicate them here.

## Layout

```
ext/                     Extension infra shared across products: sidecar,
                         telemetry, remote_config, otel_config, process_tags,
                         agent_info, crashtracking, configuration, logging,
                         signal/pcntl handlers, startup.
tracer/                  Tracer-specific C: ddtrace.c, hook/ (userland hooks),
                         integrations/, limiter/, priority_sampling/,
                         tracer_tag_propagation/, distributed tracing,
                         code_origins, asm_event, collect_backtrace,
                         live_debugger, inferred_proxy_headers,
                         endpoint_guessing, coms/auto_flush (trace sender).
src/                     PHP userland: DDTrace/, api/, bridge/, dogstatsd/.
components/              PHP-agnostic C, one .h/.c + tests/ each (CMake).
components-rs/           PHP-specific Rust wrapping libdatadog.
libdatadog/              Datadog shared Rust library (git submodule).
zend_abstract_interface/ Zend engine abstraction across PHP versions (ZAI).
appsec/                  Application security (extension + C++/Rust helpers).
profiling/               Rust profiler extension.
loader/                  SSI loader.
tea/                     Test harness for ZAI/components.
tests/                   .phpt tests + PHPUnit (tests/Integration/).
tooling/                 Packaging, installers, artifact helpers.
dockerfiles/             Dev/CI images and package verification.
```

> The tracer C was split out of `ext/` into `tracer/` (#3912). Stray
> `ext/hook/`, `ext/integrations/` etc. are build artifacts, not sources —
> the tracked sources live under `tracer/`.

## Subsystem map

| Area | What | Guide |
|---|---|---|
| `ext/` | Shared runtime infra: config, sidecar IPC, telemetry, remote config, signals | [ext.md](ext.md) |
| `tracer/` | Instrumentation engine: spans, sampling, serialization, hooks, trace sender | [tracer.md](tracer.md) |
| `src/` | PHP userland: Tracer/Span API, propagators, ~40 integrations | [userland.md](userland.md) |
| `components/`, `components-rs/`, ZAI | PHP-agnostic C, Rust FFI bridge, Zend version abstraction | [components.md](components.md) |
| sidecar | Rust background service for async I/O (trace/telemetry/RC upload) | [sidecar.md](sidecar.md) |
| `appsec/` | Application security extension + WAF helper process | [appsec.md](appsec.md) |
| `profiling/` | Rust profiler extension (CPU/wall/alloc/exception) | [profiling.md](profiling.md) |

## Architecture

**PHP version support.** PHP 7.0 → 8.5+. Version-specific behavior gates on
`PHP_VERSION_ID` macros; `zend_abstract_interface/` (ZAI) absorbs Zend API
differences (see [components.md](components.md)). Common C is gradually
extracted into PHP-agnostic `components/`.

**Trace sender.** Traces are encoded with msgpack and uploaded asynchronously
so PHP request threads never block. On Linux this is routed through the
sidecar (see below); the legacy in-process background sender (`tracer/coms.c`)
remains as fallback. See [tracer.md](tracer.md) and
[../../architecture.md](../../architecture.md) for the background-sender
design.

**Sidecar.** A Rust background service (`libdatadog/datadog-sidecar*`, driven
from `ext/sidecar.{c,h}`) that offloads I/O off request threads: telemetry,
trace upload, crashtracking, DogStatsD, remote config, live debugger, and
appsec data. PHP ↔ sidecar over IPC (`ddog_SidecarTransport`). It survives
request crashes and is shared across language tracers. See
[sidecar.md](sidecar.md).

- `DD_TRACE_SIDECAR_CONNECTION_MODE` = `auto` (default) | `subprocess` |
  `thread`. `auto` tries subprocess, falls back to thread.
- **Thread mode is not `pcntl_fork()`-safe** — apps that fork must use
  subprocess mode. There is no `docs/SIDECAR_CONNECTION_MODES.md`; the modes
  are documented in comments in `ext/sidecar.c`.

**Rust integration.** `libdatadog/` compiles into the extension;
`compile_rust.sh` (invoked from the Makefile) drives it. Minimize FFI surface;
headers are generated with cbindgen (see [components.md](components.md)).
Toolchain is pinned — see `Cargo.toml` (`rust-version`) and
`profiling/rust-toolchain.toml`, not a hardcoded version.

## Configuration & INI

- Runtime config: `DD_*` env vars and `datadog.*` INI keys, in two x-macro
  tables — `ext/configuration.h` (shared/infra) and `tracer/configuration.h`
  (tracer-specific, the majority of keys).
- System INI is installed as `.../conf.d/98-ddtrace.ini`.
- Inspect: `php --ri ddtrace`, or `php -i | grep -E 'datadog\.|ddtrace\.'`.

## Coding conventions

- **C/C++:** follow existing patterns; gate version differences on
  `PHP_VERSION_ID`; keep `components/` free of the Zend API; treat the trace
  sender / sidecar paths as thread-safe.
- **PHP:** PSR-2. `composer lint`, `composer fix-lint`. Userland in `src/`.
- **Rust:** standard conventions; keep the FFI boundary small.

## Pointers (don't duplicate these here)

- Operating rules: [../general.md](../general.md)
- Building any artifact locally:
  [../ci/building-locally.md](../ci/building-locally.md)
- Reproducing CI jobs locally: [../ci/index.md](../ci/index.md)
- Debugging (gdb, appsec integration, system tests):
  [../debugging/index.md](../debugging/index.md)
- Component design + background sender + PHP-version code:
  [../../architecture.md](../../architecture.md)
- Contributor setup / linting / local testing:
  [../../CONTRIBUTING.md](../../CONTRIBUTING.md)
- Version is in `VERSION`; supported framework versions in
  `integration_versions.md`; libdatadog updates in `LIBDATADOG.md`.
