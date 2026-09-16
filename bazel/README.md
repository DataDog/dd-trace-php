# Granular Linux build

This directory contains the additive Bazel build. The migration is gated in
the order described below; a later stage must not be treated as validated
until all earlier stage targets pass locally and with Buildbarn.

1. `//:stage1`: pinned bootstrap, platforms, Rust toolchains, and matrix data.
2. PHP SDK generation and runtime/SAPI targets.
3. Shared Rust/native dependencies and generators.
4. Tracer, profiler, AppSec, loader, and sidecar artifacts.
5. Buildbarn CI lanes, comparison, cache reuse, and timing reports.

Install the pinned BuildBuddy CLI with:

```sh
./tools/bazel/bootstrap-bb.sh
```

The script downloads only a release asset selected by OS and architecture,
verifies its SHA-256 digest, and writes `bb` to `./build/bin`. Dependency
downloads happen during Bazel repository setup; build actions are expected to
run with networking disabled.

The Buildbarn endpoints and credential helper are deliberately not committed
to the public `.bazelrc`. CI supplies the Datadog-managed rc file so workload
tokens never enter action environments, cache keys, profiles, or artifacts.

`//:stage1` is the bootstrap foundation gate, not a claim that migration stage
one is complete. In particular, target-config Rust/C compilation stays out of
the gate until the LLVM 20 compiler/runtime archives and the four explicit
glibc 2.17/dynamic-musl sysroots are pinned. The Rust probe runs in the exec
configuration to enforce the generator boundary in the meantime.

Run the deterministic-output check with the pinned CLI first in `PATH` so its
nested Bazel invocations cannot fall back to a host wrapper:

```sh
PATH="$PWD/build/bin:$PATH" ./build/bin/bb detect nondeterminism \
  --bazel_command='build //:stage1 --config=linux-amd64-glibc'
```
