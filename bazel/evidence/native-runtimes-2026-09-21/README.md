# Native LLVM runtime verification and RBE measurements

The production labels use LLVM 20.1.4 native Bazel compilation actions. Manual
CMake targets remain available for reference. Compiler versions, product
optimization settings, glibc 2.17, dynamic musl, and native worker selection are
preserved.

## Validation

- 202 tracer products passed: 101 per architecture, including every DSO,
  split debug file, and ELF marker. This is build and ELF coverage; it is not
  execution of every product under every PHP runtime.
- Both normal architecture action graphs have one core runtime per libc,
  no ASan compilation, no runtime smoke executable, no CMake LLVM runtime
  action, and no opposite-architecture runtime compilation. They contain
  508 runtime compilation/assembly actions on AMD64 and 734 on ARM64.
  Each architecture graph also has exactly two GNU libunwind build actions,
  one per libc.
- Ordinary C/C++ compilation has no dependency on runtime archive/link
  outputs. ELF inspection does not carry Clang, LLD, or CMake inputs.
- CMake/native ABI inventories match for builtins, libc++, libc++abi,
  libunwind, CRTs, generated headers, ASan libraries and helper/export files.
  SONAMEs, dynamic dependencies, glibc floors and musl dependencies pass.
  The explicit x86-64 musl CMake target was also freshly rebuilt and compared.
- 9,999 runtime files match across two fresh output roots, including 1,599
  objects/libraries plus headers, generated files and startup objects. These
  roots use local x86-64 processwrapper execution, including ARM64 cross builds;
  matching-native-worker execution is covered by the separate RBE and smoke gates.
- CMake and native C++ exception/TLS executables both passed on matching
  architectures for all four ABI combinations.
- C++ exception/TLS, Rust execution/proc-macro unwind, native launcher,
  GNU libunwind, libxml2 NTS/ZTS, dedicated glibc ASan, and available PHP 8.5
  tracer native smoke gates passed on matching architectures.

The checked-in manifests were regenerated using the actual sysroot directory
and pinned maintenance utilities. This corrected ASan RPC-header detection;
normal runtime definitions were identical. Final ASan revalidation is recorded
with the correction.

## Measurement controls

Bazel 9.2.0; unchanged compiler/source/sysroot identities; matching native worker
pools; repository preparation outside build timing. Fresh forced runs bypass
remote action-cache reads and use empty client output roots. They do not purge
the shared CAS or worker filesystem caches. Cached runs use fresh clients;
no-op and edit runs retain their server/output root after untimed warmup.
C/Rust edits append one unique comment and restore the original bytes.
Forced and fresh-cache graphs have identical declared input groups and
execution action keys. Only client runfiles manifest/tree keys differ, because
those encode their fresh output-root paths; the cache-workload parity records
identify these expected local differences.

Full profiles, compact execution logs, BEP, effective flags, source hashes,
action graphs and ByteStream transfer logs are retained in the persistent
benchmark directory. Artifact paths and SHA-256 hashes are listed in the
artifact index. Queue and worker execution totals are aggregate durations,
not wall time. Transfer totals cover Bazel client ByteStream payloads, including
retries and compression, excluding RPC framing, inline results and worker-to-CAS
traffic. Worker input-byte totals are logical inputs, not network measurements.

| Architecture | Mode | CLI elapsed (s) | Bazel elapsed (s) | Executed / cache-hit spawns | Worker execution (s) | Queue (s) |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| amd64 | forced | 4,533.56 | 4,340.46 | 11,878 / 0 | 4,038.24 | 191,352.47 |
| amd64 | cached | 171.80 | 70.67 | 0 / 11,878 | 0.00 | 0.00 |
| amd64 | noop | 23.14 | 2.16 | 0 / 0 | 0.00 | 0.00 |
| amd64 | c-edit | 2,124.42 | 2,102.18 | 505 / 0 | 933.16 | 90,340.16 |
| amd64 | rust-edit | 223.47 | 203.61 | 2 / 0 | 199.35 | 100.31 |
| arm64 | forced | 6,527.60 | 6,367.70 | 12,104 / 0 | 6,563.45 | 284,015.00 |
| arm64 | cached | 167.75 | 71.53 | 0 / 12,104 | 0.00 | 0.00 |
| arm64 | noop | 22.44 | 2.02 | 0 / 0 | 0.00 | 0.00 |
| arm64 | c-edit | 2,864.61 | 2,842.36 | 505 / 0 | 1,594.89 | 118,968.44 |
| arm64 | rust-edit | 345.18 | 325.82 | 2 / 0 | 320.35 | 161.56 |

All rows build the 101-product architecture aggregate at jobs 50. CLI elapsed
includes instrumented client completion and log flushing; the separately reported
Bazel elapsed time excludes some of that overhead. Do not interpret the shorter
Bazel no-op time as end-to-end latency. Fresh-client runs served every spawn from cache,
no-op runs produced no spawns, and all four edits executed zero `LlvmRuntime*`
actions. Original source bytes were restored. The controlled edits change only
one comment in `tracer/memory_limit.c` or `components-rs/lib.rs`; the source
snapshots verify that no other workspace file changed during the measurements.

| Architecture | Mode | Uploaded ByteStream bytes | Downloaded ByteStream bytes |
| --- | --- | ---: | ---: |
| amd64 | forced | 1,532,237,352 | 21,067,610 |
| amd64 | cached | 0 | 21,067,610 |
| amd64 | noop | 0 | 0 |
| amd64 | c-edit | 1,128,821 | 197,353 |
| amd64 | rust-edit | 10,223 | 0 |
| arm64 | forced | 968,773,260 | 21,803,080 |
| arm64 | cached | 0 | 21,803,080 |
| arm64 | noop | 0 | 0 |
| arm64 | c-edit | 1,130,595 | 205,993 |
| arm64 | rust-edit | 10,225 | 0 |

The incremental C workload still rebuilds 101 product objects and their
downstream links, symbol processing, debug splits and ELF checks: 505 remote
actions on each architecture. Runtime reuse removes unchanged runtime work; it does not
remove these affected product actions or the observed worker queue.

Three forced samples per concurrency used the x86-64 glibc core runtime,
with order rotated across rounds (10/25/50, 25/50/10, 50/10/25).

| Jobs | Median instrumented seconds | Samples |
| --- | ---: | ---: |
| 10 | 47.35 | 3 |
| 25 | 46.37 | 3 |
| 50 | 44.75 | 3 |

The default is 25, the lowest concurrency within 5% of the best median.
All full-matrix/cache/no-op/edit measurements explicitly use jobs 50.
This is a representative runtime workload on the observed staging pool, not a
claim that one concurrency is optimal for every workload or future capacity.

## Interpretation and limits

Bazel 9.2 output-directory naming is relative to top-level option baselines.
Runtime configuration checksums converge across product/debug/LTO settings,
and matrix variants share their runtime actions. Changing top-level flags
between separate commands can still rename output paths and miss the cache.
These measurements keep those flags consistent; source edits preserve reuse.
A global legacy-naming experiment was reverted after it introduced duplicate
configurations.

Native Bazel compilation exposes work previously hidden inside CMake/make
spawns, so raw action counts are not comparable to the old coarse build actions.
The relevant checks are duplicate elimination, actual worker cost and cache reuse.

The September 18 CMake measurements used earlier workspace inputs and are
historical context, not a like-for-like speedup denominator. No multiplier is
claimed. Buildbarn queue capacity remains external; `resource_set` does not
reserve remote resources. SSI bundles and full tracer runtime acceptance remain
outside this change.

## Evidence and reproduction

[Benchmark summary](benchmark-summary.json) contains the 19 passing measurements
(10 matrix modes and nine concurrency samples). An interrupted earlier forced
attempt is marked invalid and excluded; an earlier preparation failure produced
no timed result. Per-run `benchmarks/` directories preserve exact commands,
effective flags, metrics, compressed logs and graph audit results.
[Raw artifact index](benchmark-artifacts.json) records persistent local paths,
byte sizes and SHA-256 digests for large action graphs, source snapshots,
profiles and execution/transfer logs. Those large artifacts remain in the
workspace cache; rerun the documented benchmark tool to reproduce them elsewhere.

[Final build commands](final-build-commands.json) and their compressed logs
record the final ASan correction and matrix recheck.
[Tracer artifacts](tracer-matrix.json) contain all 202 DSO/debug/marker hashes.
[Reproducibility inventory](reproducibility.json.gz) contains all 9,999 comparisons.
The four `*-parity.json` inventories and `crt-headers-asan-support-parity.json`
record the CMake comparisons. `behavior/` records matching-architecture C++
execution inputs, rules and results. Its harness sources have a `.txt` suffix
to avoid introducing a Bazel package with external verification binaries; remove
that suffix when reconstructing the isolated workspace.

Final action-key and source-input parity records verify that maintenance,
ASan-only and documentation changes made after timing do not change the measured
normal tracer workload. The normal remote default was then set to 25 jobs;
the benchmark commands retain their explicit 50-job override.
[Final checks](final-checks.json) record inventory/parser tests, formatting,
Python compilation and restoration of the temporary source edits.
`published-*-action-key-parity.json` and `published-source-input-parity.json`
repeat workload parity after the evidence and documentation are in place.
[Action comparison helper](compare-action-keys.py) compares command keys, output
paths and declared input groups from two captured aquery JSON files.
`SHA256SUMS` covers the compact evidence files checked into this directory.
