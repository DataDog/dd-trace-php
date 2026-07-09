# appsec/ — application security

## What it is

A separate PHP extension (`ddappsec.so`) plus a standalone WAF helper
process (`libddappsec-helper.so`, C++ original or Rust rewrite). The helper
runs libddwaf on request data, handles remote config and security actions;
matches tag spans via `asm_event`.

## Key files & dirs

- `appsec/src/extension/` — `ddappsec.c`,
  `commands/{client_init,request_init,request_exec,request_shutdown}.c`,
  `helper_process.c`, `configuration.c`, `msgpack_helpers.c`,
  `ip_extraction.c`.
- `appsec/src/helper/` — C++ helper: `main.cpp`,
  `client/`, `runner/`, `engine/`, `service/`, `remote_config/`,
  `subscriber/`.
- `appsec/helper-rust/` — Rust helper:
  `src/{lib,server,client,service,config,rc,telemetry}.rs`, `build.rs`,
  `CLAUDE.md`.
- `appsec/third_party/{libddwaf,libddwaf-rust,msgpack-c,cpp-base64}/`.

## How it fits

Extension MINIT/RINIT spawns the helper (subprocess) and connects over a
Unix socket. Per request, the extension sends headers/body/query via
msgpack; the helper runs the WAF and returns block/redirect decisions plus
matched rules. Matches emit `asm_event`, which [tracer/](tracer.md) attaches
to the root span on serialize.

Two decoupled `.so` files (can be disabled independently); talks to the
helper over msgpack; integrates with the tracer via `asm_event` and reads
remote config (ASM rules) via the [sidecar](sidecar.md).

## Gotchas

- Two helper implementations: C++ (reference) vs Rust (modern, the CMake
  default). `-PuseHelperCpp` is an appsec integration-test Gradle property that
  runs the tests against the C++ helper — not a build-time selector.
- Submodules required: `libddwaf`, `libddwaf-rust`, `msgpack-c`,
  `cpp-base64`, `libdatadog` — see
  [building-locally.md](../ci/building-locally.md#submodule-initialisation).
- The C++ helper needs C++17 / devtoolset on CentOS.
- `appsec/helper-rust/CLAUDE.md` has the deep guide for the Rust helper.
- Helper build differs by implementation: Gradle/Docker for Rust, CMake for
  C++.
- For build/test detail see
  [../ci/building-locally.md](../ci/building-locally.md) and
  [../debugging/index.md](../debugging/index.md).
