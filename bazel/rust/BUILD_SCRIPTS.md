# Reachable build script dispositions

Native Rust targets do not run the local Cargo build scripts. Every reachable
script has one of the following declared dispositions.

The checked-in FFI headers remain current compilation inputs. The mandatory
`//:rust_ffi_header_checks` target regenerates all seven headers with locked
cbindgen 0.29.0, uses declared Cargo metadata rather than invoking Cargo, runs
the pinned upstream deduplication algorithm, and compares every final header
byte for byte with the checked-in API.

| Package | Disposition |
| --- | --- |
| `datadog-sidecar-ffi` | Its `-rdynamic` output is test-only. An explicit action generates `sidecar.h` before the shared deduplication gate. |
| `libdd-common-ffi` | Its `cfg(cbindgen)` build script is disabled. An explicit action generates `common.h` before the shared deduplication gate. |
| `libdd-crashtracker` | `TARGET` comes from the selected rules_rs target triple. `emit_sicodes.c` is an explicit `cc_library`. The inactive `cxx` and test-fixture features do not run generators. |
| `libdd-crashtracker-ffi` | Its `cfg(cbindgen)` build script is disabled. An explicit action generates `crashtracker.h` before the shared deduplication gate. |
| `libdd-ddsketch` | `generate-protobuf` is disabled in the resolved production features; the checked-in `src/pb.rs` is a declared source input. |
| `libdd-ipc` | The host glibc probe is replaced with `polyfill_glibc_memfd` for the declared glibc 2.17 target floor and disabled for musl. Its remaining output is test-only `-rdynamic`. |
| `io-lifetimes` 1.0.11 | Its build script compiles target Rust probes after changing directory. Both locked compilers satisfy the Rust 1.63 `io_safety_is_in_std` and Rust 1.57 `panic_in_const_fn` thresholds, so those Linux results are explicit rustc cfgs. The WASI-only probe is absent on the Linux matrix. |
| `libdd-library-config-ffi` | Its `cfg(cbindgen)` build script is disabled. An explicit action generates `library-config.h` before the shared deduplication gate. |
| `libdd-live-debugger-ffi` | Its `cfg(cbindgen)` build script is disabled. An explicit action generates `live-debugger.h` before the shared deduplication gate. |
| `libdd-telemetry-ffi` | Its `cfg(cbindgen)` build script is disabled. An explicit action generates `telemetry.h` before the shared deduplication gate. |
| `libdd-trace-protobuf` | `generate-protobuf` is disabled in the resolved production features; checked-in generated Rust modules and proto inputs are declared. |
| `spawn_worker` | `trampoline.c`, `ld_preload_trampoline.c`, and `direct_entry.c` are target-configured Bazel C actions. A source patch replaces `OUT_DIR` lookup with two explicit data labels; `direct_entry` is a `CcInfo` dependency. |

The path crate `libddwaf-sys` is patched to consume an explicit output from
the shared pinned LLVM 20.1.4 bindgen execution tool and native libddwaf
`CcInfo`. The profiler ABI bindings use the same execution tool. The registry crate
`libdd-libunwind-sys` has its configure/make build script disabled and
consumes `//bazel/dependencies/gnu_libunwind:libunwind`, which exports the
GNU core, ptrace, and architecture archives in Cargo link order.

Historical pre-checkpoint validation recorded matching local and remote
hermetic x86_64-musl tracer archives with the declared Rust execution runtime,
native LLVM/sysroot toolchain, native libddwaf, pinned LLVM 20.1.4 bindgen, and
GNU libunwind. The remote archive was byte-identical to the local output at
that recorded source scope.

After the resolver-2 target/execution split, historical validation also
recorded an offline local x86_64-glibc tracer archive build. Its configured
Rustc aquery matched Cargo unit features and direct dependencies for every
divergent package identity; target-only rustc flags were absent from execution
units.

Those records validate the build-script dispositions and generated graph at
their historical source scopes. The current checkpoint does not treat them as
complete tracer-product acceptance. The Rust ASan boundary, including the
pending `ring` patch, must be completed before fresh ASan product acceptance;
see [the checkpoint continuation plan](../CHECKPOINT.md#recoverable-follow-up-work).
