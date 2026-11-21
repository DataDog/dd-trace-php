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
  `commands/{request_init,request_exec,request_shutdown}.c` (request data),
  `value_writer.c` (zvals into the request builder), `appsec_requests.c`
  (sending requests, applying verdicts), `helper_process.c` (the AppSec
  session config), `configuration_table.h` (AppSec's part of the shared
  config table; read it through `configuration.h`), `tracer_bridge.c` (calls
  into the tracer), `body_parser.c`.
- `components-rs/appsec_protocol.rs` — the typed extension ↔ helper
  protocol, included by libdatadog's sidecar (`appsec-protocol` feature,
  path in `DATADOG_SIDECAR_APPSEC_PROTOCOL`, set by `.cargo/config.toml`
  and `compile_rust.sh`).
- `components-rs/tracer_runtime/appsec.rs` — the request builder, verdict
  view and the JSON shim for the test mock helper.
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
RSHUTDOWN, MSHUTDOWN and GSHUTDOWN before (it still writes to the root span). Its globals are part of the datadog
module globals (`DDAPPSEC_G`), and its settings join the shared ZAI table
after the profiler's. During sidecar setup `ext/sidecar.c` calls
`dd_appsec_maybe_enable_helper`, which calls `ddog_sidecar_enable_appsec`.

The sidecar owns the helper client of each connection: the AppSec session
config (sent with the connection config) creates it, the application
registered for remote config binds it to a service, and closing the
connection drops it. Activation (`ASM_FEATURES`) is decided by the extension
from the tracer's remote config reader, so an inactive AppSec makes no
requests. Per request the extension sends request_init, request_exec and
request_shutdown with headers, body, and query data; the helper runs the WAF
and returns a verdict with block or redirect actions and matched rules. The
requests are typed (`components-rs/appsec_protocol.rs`), but the request data
in them is MessagePack, written by the extension with mpack
(`msgpack_helpers.c`) and decoded by the helper straight into WAF objects.
Matches emit `asm_event`, which [tracer/](tracer.md) attaches to the root
span on serialization.

## Gotchas

- The request builder and verdicts are Rust-owned: release them even when
  PHP bails out (see the `zend_try` blocks in `commands/` and
  `appsec_requests.c`).
- When AppSec blocks a request in RINIT, PHP skips RSHUTDOWN: it runs the
  other products' RSHUTDOWN itself (`datadog_rshutdown_products`).
- Without `DD_SERVICE`/`DD_ENV`, the tracer starts each request on a default
  remote config target. AppSec keeps to the target the previous request
  ended on (`ddog_remote_config_keep_asm_features_target`), reading its
  `ASM_FEATURES` live, and the helper stays bound to its application.
- Inactive requests send nothing to the helper, so bound helper clients
  submit the telemetry of RC updates as soon as they apply.
- Required submodules are `libddwaf-rust` and `libdatadog` — see
  [building-locally.md](../ci/building-locally.md#submodule-initialisation).
- `appsec/helper-rust/CLAUDE.md` has the deep guide for the Rust helper.
- For build/test detail see
  [../ci/building-locally.md](../ci/building-locally.md) and
  [../debugging/index.md](../debugging/index.md).
