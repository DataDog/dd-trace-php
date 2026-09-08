# appsec/ — application security

## What it is

A separate PHP extension (`ddappsec.so`) whose Rust helper is embedded in the
tracer's libdatadog sidecar. The helper runs libddwaf on request data,
handles remote config and security actions, and reports matches through
`asm_event`.

## Key files & dirs

- `appsec/src/extension/` — `ddappsec.c`,
  `commands/{client_init,request_init,request_exec,request_shutdown}.c`,
  `helper_process.c`, `configuration.c`, `msgpack_helpers.c`,
  `ip_extraction.c`.
- `appsec/helper-rust/` — Rust helper:
  `src/{lib,server,client,service,config,rc,telemetry}.rs`, `build.rs`,
  `CLAUDE.md`.
- `sidecar/` — registers the AppSec helper with the libdatadog sidecar.
- `appsec/third_party/libddwaf-rust/` — Rust libddwaf bindings.

## How it fits

AppSec is enabled through the sidecar: during sidecar setup `ext/sidecar.c`
calls the appsec module's `dd_appsec_maybe_enable_helper` and then
`ddog_sidecar_enable_appsec` before ddappsec's first RINIT. Per request the
extension sends headers, body, and query data over the sidecar transport. The
embedded helper runs the WAF and returns block or redirect decisions and
matched rules. Matches emit `asm_event`, which [tracer/](tracer.md) attaches to
the root span on serialization.

The PHP AppSec extension remains a separate `.so`, while the Rust helper is
part of `libdatadog_php`. It communicates through msgpack over the
[sidecar](sidecar.md), emits `asm_event` data to the tracer, and consumes ASM
remote configuration through the sidecar.

## Gotchas

- Required submodules are `libddwaf-rust` and `libdatadog` — see
  [building-locally.md](../ci/building-locally.md#submodule-initialisation).
- `appsec/helper-rust/CLAUDE.md` has the deep guide for the Rust helper.
- Portable SSI builds include the helper in `libdatadog_php.so`; normal
  extension builds include it in the statically linked Rust component.
- For build/test detail see
  [../ci/building-locally.md](../ci/building-locally.md) and
  [../debugging/index.md](../debugging/index.md).
