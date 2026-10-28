# appsec/ — application security

## What it is

AppSec is part of `ddtrace.so`, built with `--enable-ddtrace-appsec` (opt-in,
requires the tracer, Linux/macOS only); the shipped artifacts enable it. It
used to be a separate extension, `ddappsec.so`; loading an old one alongside is
a fatal startup error. Its Rust helper is embedded in the tracer's libdatadog
sidecar: it runs libddwaf on request data, handles remote config and security
actions, and reports matches through `asm_event`.

## Key files & dirs

- `appsec/src/extension/` — the C part of the extension: `ddappsec.c`
  (lifecycle, see `ddappsec_api.h`),
  `commands/{client_init,request_init,request_exec,request_shutdown}.c`,
  `helper_process.c`, `configuration_table.h` (AppSec's part of the shared
  config table; read it through `configuration.h`), `tracer_bridge.c` (calls
  into the tracer), `body_parser.c`.
- `components-rs/appsec_body/` — Rust JSON/XML request body parsers.
- `appsec/helper-rust/` — Rust helper:
  `src/{lib,server,client,service,config,rc,telemetry}.rs`, `build.rs`,
  `CLAUDE.md`.
- `sidecar/` — registers the AppSec helper with the libdatadog sidecar.
- `appsec/third_party/libddwaf-rust/` — Rust libddwaf bindings.
- `appsec/CMakeLists.txt` — builds the test mock helper, runs the extension
  tests (`make xtest`) and lint, and can build `ddtrace.so` with CMake
  (`DD_APPSEC_DDTRACE_ALT`).

## How it fits

`ext/datadog.c` drives AppSec's lifecycle: its MINIT and RINIT run after the
tracer's (it needs the tracer's PHP functions and the root span), its
RSHUTDOWN, MSHUTDOWN and GSHUTDOWN before (it still writes to the root span and
says goodbye over the sidecar connection). Its globals are part of the datadog
module globals (`DDAPPSEC_G`), and its settings join the shared ZAI table
after the profiler's. During sidecar setup `ext/sidecar.c` calls
`dd_appsec_maybe_enable_helper`, which calls `ddog_sidecar_enable_appsec`.

Per request the extension sends headers, body, and query data over the
sidecar transport. The embedded helper runs the WAF and returns block or
redirect decisions and matched rules. Matches emit `asm_event`, which
[tracer/](tracer.md) attaches to the root span on serialization.

## Gotchas

- AppSec shares the tracer's vendored mpack, configured in
  `tracer/vendor/mpack/mpack-config.h`: it allocates with emalloc, so only
  call allocating mpack functions on PHP threads.
- When AppSec blocks a request in RINIT, PHP skips RSHUTDOWN: it runs the
  other products' RSHUTDOWN itself (`datadog_rshutdown_products`).
- Required submodules are `libddwaf-rust` and `libdatadog` — see
  [building-locally.md](../ci/building-locally.md#submodule-initialisation).
- `appsec/helper-rust/CLAUDE.md` has the deep guide for the Rust helper.
- For build/test detail see
  [../ci/building-locally.md](../ci/building-locally.md) and
  [../debugging/index.md](../debugging/index.md).
