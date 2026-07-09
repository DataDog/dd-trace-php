# components/ + components-rs/ + zend_abstract_interface/ (ZAI)

## What it is

- `components/` — PHP-agnostic C utilities (no Zend API), each with its own
  `.h`/`.c` + CMake tests.
- `components-rs/` — Rust FFI bridge to libdatadog (cbindgen-generated
  headers).
- `zend_abstract_interface/` (ZAI) — Zend engine abstraction gating
  PHP-version differences.

## Key files & dirs

- `components/{string_view,log,sapi,stack-sample}/` (+ polyfill headers).
- `components-rs/lib.rs` — modules: `agent_info`, `log`, `remote_config`,
  `sidecar`, `stats`, `telemetry`, `trace_filter`, `bytes`.
- `components-rs/{common,datadog,sidecar,telemetry,crashtracker,
  library-config,live-debugger}.h` — cbindgen-generated.
- `components-rs/Cargo.toml`.
- `zend_abstract_interface/{hook,config,env,zai_string,
  sandbox/{php7,php8},interceptor/{php7,php8},jit_utils,exceptions,headers,
  json,uri_normalization}/`.

## How it fits

[ext/](ext.md) and [tracer/](tracer.md) `#include` component headers
(symbols prefixed `datadog_php_`); components compile via `config.m4`.
`components-rs` compiles into `libdatadog_php.a` and links in; C calls
`#[no_mangle] extern "C"` functions. `make generate_cbindgen` regenerates
headers (CI checks they're up to date).

ZAI: `tracer/` calls `zai_hook_install()`; ZAI absorbs PHP7 vs PHP8
(`zend_observer`) differences so callers avoid scattered `PHP_VERSION_ID`
checks.

## Gotchas

- No Zend API allowed in `components/`.
- `components/container_id/` is a stale build-artifact dir (no source);
  container ID access is in the Rust bridge (`ddtrace_get_container_id`).
- Version gating is concentrated in ZAI (`hook.c`, `sandbox/php{7,8}`,
  `interceptor/php{7,8}`) — put new version-dependent logic there, not in
  scattered `PHP_VERSION_ID` checks elsewhere.
- Keep the FFI surface small: don't leak libdatadog structs into C.
- Regenerate cbindgen headers whenever `lib.rs` changes.
