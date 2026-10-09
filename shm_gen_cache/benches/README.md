# sgc_bench

The cache's throughput suite, a Cargo bench with its own harness. It drives
the crate through its Rust API (default) or its C API (`--api c`), with the
configuration resolved at run time, as in production.

```sh
cargo bench -p shm_gen_cache --bench sgc_bench -- --list
cargo bench -p shm_gen_cache --bench sgc_bench -- --quick --verify
cargo bench -p shm_gen_cache --bench sgc_bench -- --json out.json
cargo bench -p shm_gen_cache --bench sgc_bench -- --api c   # the C API
# Linux: the shared 4 KiB-page mapping a multi-process deployment gets
cargo bench -p shm_gen_cache --bench sgc_bench -- --no-huge-pages
```

`-- --help` (any unknown flag) prints the options. An empty `--json ""`
writes no report. `--verify` compares every hit's bytes with the expected
value and makes the run fail on a mismatch or a failed registration; it is
slower and not for timing.

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

On Linux, worker *t* is pinned to the *t*-th CPU of the affinity mask
(wrapping), so each thread count runs in one fixed placement; choose it
with `taskset`. `--no-pin` leaves placement to the scheduler, which may put
the workers on one CCD in one run and spread them over two in the next, so
contended scenarios become bimodal run to run.

## What is measured

The configuration is a `Config` resolved once at startup. Each worker
thread registers its own participant. `--api` selects how the cache is
driven (`api.rs`):

- `rust` (default): `Cache::initialize` on the bench's mapping and
  `RuntimeParams`, with the occupancy mode (`Estimated` for this
  configuration) selected from the resolved values at run time: the same
  monomorphisation the C API uses, so the numbers are those of a production
  embedding, not of a `static_config!` whose parameters fold to constants.
  The cache code is compiled into the bench with LTO and may be inlined into
  the timed loop, as in a Rust caller.
- `c`: `ddog_sgc_cache_init_in` on the bench's mapping, one
  `ddog_sgc_participant_register` per worker, and exactly one
  `ddog_sgc_lookup` or `ddog_sgc_insert` call per operation, as in a C
  caller of the staticlib. The entry points are called through function
  pointers hidden from the optimizer (`std::hint::black_box`), so LTO cannot
  inline them; each operation costs one indirect call (a C caller makes a
  direct one, with no cross-language LTO).

The header's `backend=` reads `rust-api` or `c-api`. The scenarios, setup,
timing and report are the same code for both.

## Building for A/B runs

The default `bench` profile inherits `release` (fat LTO, one codegen unit)
and keeps `panic = "unwind"`. Production builds use `tracer-release`, which
additionally sets `panic = "abort"`; build with it for comparisons:

```sh
cargo bench --profile tracer-release -p shm_gen_cache --bench sgc_bench --no-run
```

`--no-run` prints the absolute path of the binary under the workspace
target directory (the repository root's `target/`, not one inside this
crate), e.g. `../target/tracer-release/deps/sgc_bench-<hash>` relative to
`shm_gen_cache/`.

## A/B comparisons

`ab_bins.py` runs two prebuilt binaries interleaved, alternating which goes
first in each round, and reports per-scenario speedups (cur/ref
throughput; < 1 means cur is slower) with a noise estimate and verdicts,
plus geomeans per group. For example, to measure a change to the crate,
build the bench from both revisions and compare (from `shm_gen_cache/`):

```sh
git worktree add /tmp/sgc-base <base-rev>
(cd /tmp/sgc-base && cargo bench --profile tracer-release -p shm_gen_cache \
  --bench sgc_bench --no-run)   # prints the base binary's path
cargo bench --profile tracer-release -p shm_gen_cache --bench sgc_bench --no-run
uv run benches/ab_bins.py --ref-bin <base binary> --cur-bin <head binary> \
  --json ab.json
# The C API path:
uv run benches/ab_bins.py ... --bench-args="--api c"
# Linux, shared 4 KiB-page mapping:
uv run benches/ab_bins.py ... --bench-args=--no-huge-pages
# A/A run, for the noise floor:
uv run benches/ab_bins.py --ref-bin X --cur-bin X --quick
```

`--bench-args` must use the `=` form when its value starts with `--`. The
default is 5 rounds (`--rounds`); `--quick`, `--filter` and `--threads` are
passed through to both binaries.

## Layout

| module | contents |
|---|---|
| `sgc_bench.rs` | `main`, API and occupancy-mode dispatch |
| `api.rs` | the Rust and C API backends behind one interface |
| `config.rs` | cache configuration, scenario matrix |
| `rng.rs` | `fmix64`, SplitMix64, `hash_bytes`, shuffle, Zipf alias sampler |
| `data.rs` | key universe: lengths, hashes, key/value byte generation |
| `model.rs` | two-generation reference model |
| `mapping.rs` | shared / THP mappings, THP coverage, setup inserts |
| `phase.rs` | timed phase: workers, stage barrier, per-rep timing, model replay |
| `scenarios.rs` | `mixed`, `lookup_hit`/`lookup_miss`, `insert_new` families |
| `report.rs` | names, `--list`, header, table, JSON |
| `platform.rs` | QoS (macOS), `--pin` (Linux), CPU location (Apple), spin hint, clock |
| `cli.rs` | options |
| `ab_bins.py` | A/B of two prebuilt binaries: interleaved rounds, noise model, verdicts |

The coordinating thread sleeps between stages with `thread::park` (woken by
the last worker to arrive); it does not take part in the timing: a
repetition ends at the latest worker's own end timestamp. `--bench`, which
`cargo bench` appends, is accepted and ignored.

## Historical results (2026-10-07)

Until this bench gained `--api c`, the C API was measured by a separate C++
program, which started as the benchmark of the C++ implementation this crate
was ported from and could be built against either. These results compare
that implementation with this crate, and are not reproducible from this
tree; they are kept as context for the port's performance.

Geomean of per-scenario speedups, full suite, 5 rounds. Linux: AMD Ryzen 9
9950X (Zen 5) VM, 32 vCPUs, clang 21.1.8; macOS: Apple M-series, Apple clang
17.

| run | ALL | mixed | lookup_hit | lookup_miss | insert_new |
|---|---|---|---|---|---|
| this crate (C API) / C++, Linux, THP | **0.952** | 0.962 | 0.926 | 0.917 | 0.983 |
| this crate (C API) / C++, Linux, no huge pages | **0.965** | 0.978 | 0.930 | 0.911 | 1.010 |
| this crate (C API) / C++, macOS | **0.978** | 0.974 | 1.020 | 0.936 | 0.968 |
| Rust API / C API (C++ program), Linux, THP | **1.017** | 1.007 | 1.008 | 1.087 | 1.027 |
| Rust API / C API (C++ program), Linux, no huge pages | **1.013** | 1.008 | 0.992 | 1.069 | 1.031 |
| Rust API / C API (C++ program), macOS | **0.992** | 0.984 | 0.986 | 1.025 | 1.018 |

So the crate measured ~5% slower than the C++ implementation on Linux and
~2% on macOS, and the Rust API (cache code inlined into the timed loop) at
parity with the C API (one call per operation), except `lookup_miss`, 7-9%
faster on Linux. Those runs were not pinned; their 4- and 8-thread
insert-heavy scenarios were bimodal run to run.
