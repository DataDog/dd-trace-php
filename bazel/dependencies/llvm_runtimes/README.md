# LLVM 20.1.4 runtime actions

The public `{x86_64,aarch64}_{glibc,musl}` labels build native Bazel actions using
the pinned compiler distributions. `LlvmRuntimeInfo` remains importable from
`runtime.bzl`. Normal builds compile builtins/CRT, libunwind, libc++abi and libc++;
glibc sanitizer platforms select a separate ASan target. Runtime smoke programs
are only reachable through explicit validation targets.

The bootstrap toolchain resolves execution tools and target sysroots without
using the consuming C++ toolchain. It supplies separate compiler, linker,
archiver and inspection closures. The incoming runtime transition removes
product flags and Rust execution-transition bookkeeping while retaining host
and execution-platform selection. Each target ABI has one core configuration
across PHP variants, Rust profiles, build scripts and proc macros.

Bazel 9.2 derives transition output-directory names relative to top-level
options. Configurations converge, but changing command-line platform or profile
flags between invocations can still rename output paths and miss the cache.
Keep top-level flags identical when comparing runs; this does not affect sharing
within a matrix or reuse after source edits. No global output-naming override is
required.

Consumers should use `compile_inputs`, `builtin_inputs`, `link_inputs`,
`sanitizer_inputs` and `validation_inputs`. `header_root` is independent of
`resource_dir`; compilation does not require assembled runtime trees or C++
archives. The compatibility `files` field includes the complete installation;
new consumers should choose the narrower inputs. Individual output groups are
`headers`, `builtins`, `crt`, `libunwind`, `libcxxabi`, `libcxx`, `libraries`,
`asan`, `validation` and `install`.

For example:

```sh
bb build //bazel/dependencies/llvm_runtimes:x86_64_glibc_native_builtins
bb build //bazel/dependencies/llvm_runtimes:x86_64_glibc_native_probe
bb build //bazel/dependencies/llvm_runtimes:x86_64_glibc_asan_native_probe
bb build //bazel/dependencies/llvm_runtimes:aarch64_musl_native_probe \
  --config=remote-hermetic-arm64
```

The compile inventories, generated headers and flags in `manifests/` come from
LLVM's CMake configuration for all four target combinations. Matching
static/shared compilation commands reuse objects; differing visibility or
exception flags retain distinct objects. Archives use deterministic indexes.
Source/build path maps remove output-root paths. Shared libraries retain the
reference SONAMEs and dynamic libc dependencies, including musl.

`*_cmake` targets retain the old implementation for explicit reference builds.
They are tagged `manual` and require the corresponding platform, for example:

```sh
bb build //bazel/dependencies/llvm_runtimes:x86_64_glibc_cmake \
  --config=linux-amd64-glibc --build_tag_filters=
```

To regenerate inventories, first build each reference target on an x86-64
execution host with its matching `--config=linux-{amd64,arm64}-{glibc,musl}`.
Clear the repository's default manual-target filter with `--build_tag_filters=`.
Use the resulting execroot and a work directory outside the source tree:

```sh
python3 bazel/dependencies/llvm_runtimes/generate_manifest.py \
  --execroot /path/to/execroot/_main --work /path/to/manifest-work
python3 bazel/dependencies/llvm_runtimes/manifest_test.py
```

This maintenance command performs configuration only. Ordinary builds never
run LLVM CMake or nested make. `--export-only` reuses recorded configurations;
`--target=x86_64_glibc` limits regeneration to one variant. Manifests record the
source archive and reference-script SHA-256 values.

Compare built outputs using `tools/bazel/compare-llvm-runtimes.py`: `--native`
is the native core `.build` directory, `--reference` is the CMake `.runtime`
directory, and `--asan` optionally selects the separate ASan `.build` directory.
The comparison checks static symbol multiplicities, dynamic symbol inventories,
SONAMEs/dependencies, forbidden RPATH/TEXTREL metadata and glibc 2.17 or dynamic
musl compatibility. Behavior is checked separately on matching native workers.

# Measurements

Normal builds accept remote cache hits and retain the Bazel server and outputs.
The `rbe-forced` and `rbe-cached` configs are explicit measurement modes. A fresh
output base is also necessary for forced execution: disabling remote hits does
not invalidate retained local outputs. Forced mode does not purge the shared CAS
or worker filesystem caches; it measures fresh client outputs with action-cache
reuse disabled. `resource_set` only informs local
scheduling; it does not reserve remote worker capacity. See the
[Bazel action API](https://bazel.build/versions/9.0.0/rules/lib/builtins/actions#run).

`tools/bazel/benchmark-runtimes.py` retains all output bases and records commands,
source hashes, workspace diff, effective flags, full profiles, compact execution
logs, gRPC logs, BEP metrics and action graphs. It requires Python 3 and `zstd`.
Use a persistent directory with sufficient disk space, outside the repository:

```sh
python3 tools/bazel/benchmark-runtimes.py --root /path/to/bench --arch amd64 \
  --mode prefetch //bazel/products/tracer:ddtrace_fat_amd64_all
python3 tools/bazel/benchmark-runtimes.py --root /path/to/bench --arch amd64 \
  --mode forced --jobs 50 //bazel/products/tracer:ddtrace_fat_amd64_all
python3 tools/bazel/benchmark-runtimes.py --root /path/to/bench --arch amd64 \
  --mode cached //bazel/products/tracer:ddtrace_fat_amd64_all
python3 tools/bazel/benchmark-runtimes.py --root /path/to/bench --arch amd64 \
  --mode noop //bazel/products/tracer:ddtrace_fat_amd64_all
```

Repository analysis/prefetch and iterative warmup occur outside the timed build.
Use `--mode c-edit --edit ext/SOURCE.c` or `--mode rust-edit --edit PATH.rs`
with a source used by the selected target. The script restores the original
bytes and refuses to overwrite a concurrent edit. Inspect execution counts to
verify unchanged runtimes are not rebuilt. Repeat with `--arch arm64` and the
arm64 target to preserve native execution workers.

For a concurrency comparison, repeat the same representative target at least
three times for each of `--jobs 10`, `25`, `50` in forced mode. Pass the resulting
`result.json` files to `--summarize`. It groups identical source snapshots,
targets, architectures and modes, then selects the lowest concurrency within
5% of the best median. The measured default is 25: its 46.37-second median
was within 3.6% of the 44.75-second best median at 50; 10 was outside the 5%
threshold. The full matrices were measured at 50. See the
[measurement record](../../evidence/native-runtimes-2026-09-21/README.md).

Aggregate subprocess time and queue time are reported separately. Summed input
bytes are logical action inputs, not network transfers. Transfer totals cover
Bazel client ByteStream payloads (including retries/compression), excluding RPC
framing, inline results and worker-to-CAS traffic. Retained-output cache hits do not produce execution-log spawns;
BEP metrics retain that separate action-cache count.

Audit each normal single-architecture matrix with:

```sh
bb aquery 'deps(//bazel/products/tracer:ddtrace_fat_amd64_all)' \
  --config=remote-hermetic-amd64 --output=jsonproto > actions.json
python3 tools/bazel/check-runtime-graph.py actions.json --arch x86_64
```

The audit rejects sanitizer/smoke/CMake-runtime actions, duplicate equivalent
runtime compilations, opposite target architectures, runtime link outputs in
C/C++ compilation inputs, and compiler/CMake inputs in ELF inspection actions.
