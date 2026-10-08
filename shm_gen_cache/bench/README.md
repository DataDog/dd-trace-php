# sgc_bench (C++, C API)

The cache's throughput suite as a C++ program that drives this crate's
staticlib through its C API (`../shm_gen_cache.h`), the way a C or C++
embedder does: `ddog_sgc_cache_init_in` on a mapping the program owns, one
`ddog_sgc_participant_register` per worker thread, and exactly one
`ddog_sgc_lookup` or `ddog_sgc_insert` call per operation in the timed
loop. The configuration is a `ddog_sgc_Config` passed at run time, as in
production.

A port of the same suite driving the crate through its Rust API lives in
[`../benches/`](../benches/README.md). Both produce the same table and JSON
report, so either can be a side of `ab_bins.py`.

The program started as the benchmark of the C++ implementation this crate
was ported from; workloads, data, timing and output are unchanged from it,
so results remain comparable with runs recorded before it was made
self-contained.

| file | what |
|---|---|
| `sgc_bench.cpp` | the program |
| `CMakeLists.txt` | builds it against the staticlib (cargo, then link) |
| `ab_bins.py` | A/B of two prebuilt binaries: interleaved rounds, noise model, verdicts |

## Workloads

Every scenario runs on a cache of 64Ki buckets, keys of 16-64 bytes and
values of 8-512 bytes (log-uniform), with a key universe of 1Mi keys:

- `mixed/<keys>/s<skew>/t<threads>`: every operation is a lookup, and a
  miss is followed by an insert of that key's value; key popularity is
  Zipfian. Hit, insert and rotation rates are results, not inputs.
- `lookup_hit`, `lookup_miss`: the read path alone, on a prepared cache.
- `insert_new`: inserts of keys never present, which also drives rotation.

Rotations are not observable through the API; the `rot/*`, `~hit%` and
`~prm%` columns come from replaying the same operation streams through a
reference model of the two-generation semantics (exact for one thread, a
round-robin interleaving otherwise). For `t1` scenarios the measured `hit%`
must equal `~hit%`.

On Linux the cache is mapped by default as private anonymous memory advised
`MADV_HUGEPAGE` (`--huge-pages`), the one kind of memory an unprivileged
process gets transparent huge pages for under the usual `enabled=madvise`,
`shmem_enabled=never` settings; the worker threads of the one process still
share it. `--no-huge-pages` uses a `MAP_SHARED` 4 KiB-page mapping, which is
what a multi-process deployment gets. Other platforms always use the shared
mapping.

## Building

CMake runs `cargo build -p shm_gen_cache --profile tracer-release` (fat LTO,
one codegen unit, `panic=abort`; override with `-DSGC_CARGO_PROFILE=...`)
into `<build>/cargo`, then links the program against `libshm_gen_cache.a`.
`RUSTFLAGS` from the configuring environment is passed to cargo.

```sh
cd shm_gen_cache
cmake -S bench -B /tmp/sgc-bench  # Linux: -DCMAKE_CXX_COMPILER=clang++-21
cmake --build /tmp/sgc-bench -j
```

The build type defaults to `RelWithDebInfo` (`-O2 -g`). The program is
compiled with `-fno-exceptions -fno-rtti -fno-threadsafe-statics
-fno-unwind-tables -fno-asynchronous-unwind-tables`, the dialect every
recorded result was measured in, and `#error`s without the first two.

There is no cross-language LTO: a lookup or an insert costs one direct call
into the staticlib, as for any C caller of the library.

## Running

```sh
/tmp/sgc-bench/sgc_bench --list            # scenario names
/tmp/sgc-bench/sgc_bench --quick --verify  # correctness smoke
/tmp/sgc-bench/sgc_bench --json out.json   # full suite, 7 reps
```

`--verify` compares every hit's bytes with the expected value and makes the
run fail on a mismatch or a failed registration; it is slower and not for
timing. Any unknown flag prints the options (`--threads`, `--filter`,
`--reps`, `--ops`, `--pin`, ...).

## A/B comparisons

`ab_bins.py` runs two prebuilt binaries interleaved, alternating which goes
first in each round, and reports per-scenario speedups (cur/ref
throughput; < 1 means cur is slower) with a noise estimate and verdicts,
plus geomeans per group. For example, to measure a change to the crate,
build the program from both revisions and compare:

```sh
git worktree add /tmp/sgc-base <base-rev>
cmake -S /tmp/sgc-base/shm_gen_cache/bench -B /tmp/sgc-ab/base
cmake -S bench -B /tmp/sgc-ab/head
cmake --build /tmp/sgc-ab/base -j && cmake --build /tmp/sgc-ab/head -j
uv run bench/ab_bins.py --ref-bin /tmp/sgc-ab/base/sgc_bench \
  --cur-bin /tmp/sgc-ab/head/sgc_bench --json ab.json
# Linux, shared 4 KiB-page mapping:
uv run bench/ab_bins.py ... --bench-args=--no-huge-pages
# A/A run, for the noise floor:
uv run bench/ab_bins.py --ref-bin X --cur-bin X --quick
```

`--bench-args` must use the `=` form when its value starts with `--`. The
default is 5 rounds (`--rounds`); `--quick`, `--filter` and `--threads` are
passed through to both binaries.

The Rust bench can be either side as well (C API vs Rust API, i.e. the cost
of the call boundary); its build commands are in
[`../benches/README.md`](../benches/README.md).

## Historical results (2026-10-07)

Recorded while the crate was being ported, with this program built both
against the original C++ implementation and against this crate. They are
not reproducible from this tree, which no longer builds against the C++
implementation; they are kept as context for the port's performance.

Geomean of per-scenario speedups, full suite, 5 rounds, `RelWithDebInfo`.
Linux: AMD Ryzen 9 9950X (Zen 5) VM, 32 vCPUs, clang 21.1.8; macOS: Apple
M-series, Apple clang 17.

| run | ALL | mixed | lookup_hit | lookup_miss | insert_new |
|---|---|---|---|---|---|
| this crate / C++, Linux, THP | **0.952** | 0.962 | 0.926 | 0.917 | 0.983 |
| this crate / C++, Linux, no huge pages | **0.965** | 0.978 | 0.930 | 0.911 | 1.010 |
| this crate / C++, macOS | **0.978** | 0.974 | 1.020 | 0.936 | 0.968 |
| Rust bench / this program, Linux, THP (\*) | **1.017** | 1.007 | 1.008 | 1.087 | 1.027 |
| Rust bench / this program, Linux, no huge pages (\*) | **1.013** | 1.008 | 0.992 | 1.069 | 1.031 |
| Rust bench / this program, macOS | **0.992** | 0.984 | 0.986 | 1.025 | 1.018 |

(\*) With the staticlib built with `RUSTFLAGS=-Cllvm-args=-align-loops=64`;
without it a code-layout artifact of the staticlib made the Rust bench look
~20% faster on Linux (geomean 1.205 / 1.192).

So the crate measured ~5% slower than the C++ implementation on Linux and
~2% on macOS, and the Rust bench (cache code inlined into the timed loop)
at parity with this program (one call per operation), except `lookup_miss`,
7-9% faster on Linux. The 4- and 8-thread insert-heavy scenarios
(`insert_new`, `mixed/1Mi`, `mixed/64Ki/s0.8`) are bimodal run to run on
that VM; their verdicts are mostly "within noise".
