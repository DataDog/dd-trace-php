# Rust integration

The repository-root `MODULE.bazel` and `BUILD.bazel` directly integrate these
Rust rules and gitlink repositories. There is no overlay or copy step.
`MODULE.bazel.lock` is generated state and must be updated with Bazel when
module declarations change.

The current checkpoint accepts four representative normal profiler static
archives. The remaining Rust targets and generated graphs are retained
foundations rather than completed tracer, profiler DSO, or ASan products. In
particular, completing the Rust ASan boundary, including the pending `ring`
patch, is the first follow-up item in [the checkpoint](../CHECKPOINT.md).

The setup creates three rules_rs hubs:

* `ddtrace_rust_crates` is the full workspace hub used only by stable exec
  generators such as sidecar mockgen.
* `ddtrace_tracer_crates` and `ddtrace_profiler_crates` are product hubs backed
  by `tracer-resolution.Cargo.toml` and `profiler-resolution.Cargo.toml`.

`generate_product_graph.py` derives the product resolver manifests and native
path-crate graph from product-specific `cargo tree --edges normal` and Cargo
unit-graph runs for all four Linux triples. Generate the unit graphs with the
production `--cfg tokio_unstable --cfg php_shared_build` Rust flags. Only
direct registry children of native path crates are bridged into the product
resolver manifests. The generated rules_rs annotations preserve Cargo
resolver-2 target and execution units as separate exact feature, dependency,
and rustc-flag selections. `verify_unit_features.py` compares configured
Rustc actions to those Cargo units, with explicit exemptions only for replaced
build scripts. Run buildifier on `product_graphs.bzl` after regeneration.
Historical x86_64 glibc aquery review recorded 27 divergent tracer identities
and 20 divergent profiler identities, including exact direct dependency edges.
That evidence supports the retained generated graph at its recorded source
scope; it is not a current full-product acceptance gate.
Tokio's target unit carries `taskdump`, `backtrace`, and `tokio_unstable`; its
execution unit carries none of those target-only inputs.

Apart from each synthetic resolution root, the derived product locks contain
only identities from the checked root `Cargo.lock`; name, version, source, and
checksum must match. Optional dependencies needed by either Cargo unit context
remain locked even when absent from the other context. The checked-in root
`Cargo.lock` remains byte-for-byte unchanged.
`assert_product_feature_resolution()` is a
mandatory package-load gate. It checks the key tracer/profiler feature sets
and rejects criterion or AWS-LC edges. In particular, tracer `libdd-common`
must not gain `bench-utils` or `test-utils`; profiler intentionally has
`test-utils` through `libdd-profiling`. The generated registry inventory also
checks every transitive normal dependency, so a forbidden package cannot hide
behind a registry-to-registry edge.

The native libddwaf contract is:

* `//bazel/products/libddwaf:libddwaf` provides target-configured CcInfo.
* `//bazel/products/libddwaf:ddwaf_h` is its public direct header.
* `//:rust_libddwaf_bindings` runs the shared registered LLVM 20.1.4 bindgen
  execution tool.
* `//:rust_native_libddwaf_sys` consumes the generated bindings and CcInfo.
* `//:rust_native_libddwaf` is the safe Rust wrapper.

The source patch replaces `OUT_DIR` probing with the explicit
`LIBDDWAF_BINDINGS` execpath. The bindgen action reproduces the upstream
allowlist, public-field visibility, `Default` derivation, and enum naming. The
same declared execution tool is used by the profiler ABI generator and loads
only the pinned LLVM 20.1.4 `libclang` and matching resource headers.

`libdd-libunwind-sys` has its nested configure/make build disabled and consumes
`//bazel/dependencies/gnu_libunwind:libunwind`. That CcInfo exports the locked
GNU core, ptrace, and architecture archives in Cargo order for all four target
platforms. LLVM libunwind remains separate and is not attached to this crate.

The retained nightly `2025-06-13` ASan configuration is designed to build
`core`, `alloc`, `compiler_builtins`, `std`, and `panic_abort` from its
checksum-locked Rust source archive. When that product configuration is
selected, its sanitizer, frame-pointer, and panic flags apply to those
libraries and target crates. Ordinary build scripts and generators remain on
stable `1.87.0`. Proc macros are uninstrumented, but use the same dated nightly
compiler as their ASan target because rustc rejects proc-macro metadata
produced by a different compiler. A dedicated transition clears the target
sanitizer, profile, and LTO flags while preserving that compiler ABI. Product
configurations also use `--strip=never` so spawn-worker trampoline/direct-entry
debug information is retained. The trampoline link order is
`-Wl,--no-as-needed -ldl -lm -lpthread`.

Historical pre-checkpoint evidence records an offline x86_64-glibc full native
archive build whose Rustc graph included those five source-built standard
library crates with `-Zsanitizer=address`, forced frame pointers, and
`panic=abort`. All 30 loaded proc macros used the dated nightly compiler without
those target flags; ordinary product build scripts used stable `1.87.0`
without instrumentation. That record predates the final native sanitizer
runtime inputs and C dependency instrumentation and explicitly makes no
cross-checkout reproducibility claim. It is retained architecture evidence,
not a passing checkpoint Rust ASan product gate.

The pending `ring` relative-manifest patch and the unvalidated full profiler
matrix were removed from the checkpoint and preserved in the recovery
worktree and checksummed archives described in [the checkpoint continuation
section](../CHECKPOINT.md#recoverable-follow-up-work). The separate ASan
runtime determinism gate passed for its recorded runtime scope; it does not
complete the Rust product. Rust ASan completion and fresh local and remote
product acceptance remain pending.

The profiler ABI generator consumes `PhpToolchainInfo` metadata and headers
from the configured, digest-pinned PHP SDK target. It never executes target
PHP or `php-config`, which also keeps cross builds valid. PHP runtime source
builds are outside the current scope.

`//:rust_ffi_header_checks` reproduces the repository's seven-header cbindgen
and deduplication flow without nested Cargo. Its synthetic metadata and every
Rust source it can parse are declared action inputs. The deduplicated outputs
must match the checked-in public headers byte for byte, and this gate is part
of `rust_native_outputs`.

The root BUILD integration is:

```starlark
load("//bazel/rust:defs.bzl", "ddtrace_rust_targets")

exports_files([
    "appsec/helper-rust/Cargo.toml",
    "components-rs/php_sidecar_mockgen/Cargo.toml",
    "sidecar/Cargo.toml",
])

ddtrace_rust_targets()
```
