# Project knowledge

The Datadog PHP tracer (`ddtrace`): a PHP extension bringing APM, distributed
tracing, profiling, and application security to PHP. Multi-language:

- **C** extension (`ext/`, `tracer/`) — low-level hooks and runtime glue.
- **Rust** (`components-rs/`, `libdatadog/` submodule, `profiling/`, appsec
  helper) — shared cross-tracer functionality, sidecar, crashtracker.
- **PHP** userland (`src/`) — high-level instrumentation.

User docs: <https://docs.datadoghq.com/tracing/languages/php/>.
For build/test/CI, see the pointers at the bottom — do not duplicate them here.

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

## Architecture

**PHP version support.** PHP 7.0 → 8.5+. Version-specific behavior gates on
`PHP_VERSION_ID` macros (e.g. `PHP_VERSION_ID >= 80500`); the
`zend_abstract_interface/` layer (ZAI) absorbs Zend API differences. Common C
is gradually extracted into PHP-agnostic `components/`.

**Trace sender.** Traces are encoded with msgpack and uploaded asynchronously
so PHP request threads never block. On Linux this is routed through the sidecar
(see below); the legacy in-process background sender remains as fallback. See
`architecture.md` for the full design.

**Sidecar.** A Rust background service (in `libdatadog/datadog-sidecar*`,
driven from `ext/sidecar.{c,h}`) that offloads I/O off request threads:
telemetry, trace upload, crashtracking, DogStatsD, remote config, live
debugger, and appsec data. PHP ↔ sidecar over IPC (`ddog_SidecarTransport`).
It survives request crashes and is shared across language tracers.

- `DD_TRACE_SIDECAR_CONNECTION_MODE` = `auto` (default) | `subprocess` |
  `thread`. `auto` tries subprocess, falls back to thread.
- **Thread mode is not `pcntl_fork()`-safe** — apps that fork must use
  subprocess mode. There is no `docs/SIDECAR_CONNECTION_MODES.md`; the modes
  are documented in comments in `ext/sidecar.c`.

**Rust integration.** `libdatadog/` compiles into the extension; `compile_rust.sh`
(invoked from the Makefile) drives it. Minimize FFI surface; headers are
generated with cbindgen. Toolchain is pinned — see `Cargo.toml`
(`rust-version`) and `profiling/rust-toolchain.toml`, not a hardcoded version.

## Configuration & INI

- Runtime config: `DD_*` env vars and `datadog.*` INI keys, defined in
  `ext/configuration.h` (x-macro table).
- System INI is installed as `.../conf.d/98-ddtrace.ini`.
- Inspect: `php --ri ddtrace`, or `php -i | grep -E 'datadog\.|ddtrace\.'`.

## Coding conventions

- **C/C++:** follow existing patterns; gate version differences on
  `PHP_VERSION_ID`; keep `components/` free of the Zend API; treat the trace
  sender / sidecar paths as thread-safe.
- **PHP:** PSR-2. `composer lint`, `composer fix-lint`. Userland in `src/`.
- **Rust:** standard conventions; keep the FFI boundary small.

## Pointers (don't duplicate these here)

- Operating rules: [general.md](general.md)
- Building any artifact locally: [ci/building-locally.md](ci/building-locally.md)
- Reproducing CI jobs locally: [ci/index.md](ci/index.md)
- Debugging (gdb, appsec integration, system tests):
  [debugging/index.md](debugging/index.md)
- Version is in `VERSION`; supported framework versions in
  `integration_versions.md`; libdatadog updates in `LIBDATADOG.md`.
