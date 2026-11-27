# llvm-bolt-align

Standalone tool image for aligning the function layouts of two ELF binaries.
Its entrypoint forwards all arguments to `llvm-bolt-align`.

The executable is fully static, built against musl. It can be copied into any
Linux image of the matching architecture without installing a C or C++ runtime:

```dockerfile
COPY --from=registry.ddbuild.io/ci/benchmarking-platform:llvm-bolt-align-<PIPELINE_ID>@sha256:<DIGEST> \
  /usr/local/bin/llvm-bolt /usr/local/bin/llvm-bolt-align
```

The source is the actual executable; `llvm-bolt-align` in the tool image is a
symlink to it. Copying the executable under either name is supported. Alignment
needs a writable temporary directory. The standalone image uses `scratch` and
provides `/tmp` with mode 1777 and `/work` for bind mounts.

Build it with the manual `build-llvm-bolt-align-image` GitLab job
(`.gitlab/benchmarks.yml`). It uses the same image builder as the PHP benchmark
image and publishes
`registry.ddbuild.io/ci/benchmarking-platform:llvm-bolt-align-<PIPELINE_ID>`
for amd64 and arm64. After a rebuild, repin the `FROM` of
`../Dockerfile` to the new tag and digest, then rebuild and repin the PHP image
(see "How to rebuild the CI Docker image" in `../../README.md`).
For a local build on a Linux build machine:

```sh
docker build --build-arg BUILD_JOBS=16 -t llvm-bolt-align .gitlab/benchmarks/container/llvm-bolt-align
```

Run it with both inputs and the output directory mounted into the container:

```sh
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$PWD:/work" llvm-bolt-align \
  baseline.so candidate.so -o-a=baseline.aligned.so -o-b=candidate.aligned.so
```

With no arguments the image prints help. Inputs need their symbol tables and
must be linked with `-Wl,--emit-relocs`. They can be built with stock GCC,
Clang, or Rust; the patched LLVM is only used to build this tool.
For GCC builds, also use `-fno-reorder-blocks-and-partition`, as required by BOLT.

Each image supports ELF inputs for its own architecture: x86-64 in the amd64
image, AArch64 in the arm64 image. These are benchmark artifacts: keep the
original binaries and compare each aligned pair together, since its layout
depends on both inputs.

## Source and patch

The Dockerfile pins LLVM [23.1.6](https://github.com/llvm/llvm-project/releases/tag/llvmorg-23.1.6)
(tag `llvmorg-23.1.6`, commit `5b10d2d74929eae78912771222c9855af97496f4`),
the latest stable release on 2026-11-17. It verifies the source archive checksum
and applies the checked-in `bolt-align.patch` before building BOLT. LLVM, musl,
and the C++ runtime are linked statically. The build rejects an ELF interpreter
or dynamic-library dependency before producing the runtime image.

The patch comes from LLVM PR [#210634](https://github.com/llvm/llvm-project/pull/210634),
using its alignment-only changes between commits
`75415f80b26f7c7177283a1680bef44c744c4886` and
`10efaa57f667e51ed10cc4f1464038a98fc22b5c`. The older relocation-order workaround
is excluded. Instead, the patch backports the upstream fix from
[#219447](https://github.com/llvm/llvm-project/pull/219447), commit
`c7d4c008350c6b66112de0278aa74cd72fedc180`, which is absent from LLVM 23.1.6.
This preserves PLT relocation order when rewriting dynamically linked binaries.

Local adaptations update the CMake source list and extend the existing
`MCOrgFragment` constructor instead of adding an ambiguous overload. Temporary
layout files use the overload of `createTemporaryFile` that closes the file.
The relocation fix uses the release's `raw_fd_ostream::pwrite` API.
Child processes explicitly select normal BOLT mode, allowing a single executable
to be copied under the `llvm-bolt-align` name without its original symlink layout.

Each image build copies only the executable into an Ubuntu test stage under the
`llvm-bolt-align` name and runs `smoke-test.sh`. It aligns independently compiled
executable and shared-library pairs, checks common function addresses, executes
the rewritten programs, and loads and calls the rewritten shared libraries.
On x86-64, it also verifies PLT relocation order and execution with mixed
`JUMP_SLOT` and `IRELATIVE` relocations to cover the backported fix.
The final `scratch` stage also executes the tool's help command.

## Build scope

The build enables only the BOLT project and requests only the `llvm-bolt` target.
It uses the system compiler and linker; it does not build Clang, LLD, or the full
LLVM tool suite. To reduce the build further:

- `LLVM_TARGETS_TO_BUILD=Native` builds one backend per platform image instead of
  compiling both X86 and AArch64 into every image.
- `BOLT_ENABLE_RUNTIME=OFF` skips the instrumentation and huge-page runtimes.
  Function alignment does not use them.
- LLVM utilities, runtimes, examples, documentation, tests, benchmarks, bindings,
  plugins, telemetry, and optional external libraries are disabled.
- Assertions are disabled; exceptions and RTTI are also off by default.
  Release optimization and LLVM threading remain enabled for the rewriting work.

`LLVM_INCLUDE_TOOLS` must stay enabled because LLVM adds the BOLT project from
its tools directory. `LLVM_BUILD_TOOLS=OFF` excludes ordinary LLVM tools from the
default build; requesting `llvm-bolt` still builds required dependencies such as
TableGen. BOLT also depends on compiler code-generation and debug-information
libraries, so those cannot simply be omitted with CMake switches.

Only LLVM, BOLT, the shared CMake/third-party directories, and libc are extracted
from the source archive. The libc headers are required by LLVM Support even
though the libc project is disabled.

Before the static build change, an experiment using Ubuntu 24.04 on `ubuntu-vm`
(Ryzen 9 9950X, 24 build jobs, one link job) compared the same patched LLVM 23.1.6
source with these feature settings:

| Configuration | Ninja build steps | Build time |
| --- | ---: | ---: |
| Previous options: X86 and AArch64, BOLT runtime enabled | 2,156 | 252.6 s |
| Previous options with only the native backend | 1,868 | Not timed |
| Reduced LLVM features | 1,860 | 195.8 s |

These are single measurements of compilation/linking, excluding configuration,
source download, and image export. The reduced build was about 22% faster.
Architecture selection removes most of the compiled work; disabling the BOLT
runtime removes another eight top-level steps. The other exclusions mostly
reduce configuration work, since the explicit `llvm-bolt` target already avoids
building unrelated tools. CI uses 16 compiler jobs per architecture, one linker
job, and a two-hour timeout. Its native BuildKit workers are configured with
60 GiB / 15 CPUs for amd64 and 118 GiB / 30 CPUs for arm64. The image-builder
job's 12 GiB allocation applies to the client container, not these workers.
Override `BUILD_JOBS` on smaller local Linux machines. These timings do not
measure the current musl build or its separate Ubuntu test stage.
