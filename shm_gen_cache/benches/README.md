# sgc_bench (Rust)

The cache's throughput suite as a Cargo bench, driving the crate through its
Rust API. It is a port of the C++ project's `bench/sgc_bench.cpp`
(`~/repos/shm_gen_cache`, the copy in [`../bench/`](../bench/README.md)), with
the same workloads, data, operation streams, timing, reference model, command
line, stdout table and JSON report, so
[`../bench/ab_bins.py`](../bench/ab_bins.py) and `ab_compare.py` accept its
binary as either side of a comparison.

```sh
cargo bench -p shm_gen_cache --bench sgc_bench -- --list
cargo bench -p shm_gen_cache --bench sgc_bench -- --quick --verify
cargo bench -p shm_gen_cache --bench sgc_bench -- --json out.json
# Linux: the shared 4 KiB-page mapping a multi-process deployment gets
cargo bench -p shm_gen_cache --bench sgc_bench -- --no-huge-pages
```

`-- --help` (any unknown flag) prints the options. An empty `--json ""`
writes no report.

The default `bench` profile inherits `release` (fat LTO, one codegen unit)
and keeps `panic = "unwind"`. Production builds, and the C++ harness's Rust
backend built through CMake, use `tracer-release`, which additionally sets
`panic = "abort"`. For A/B runs against that binary, build with the same
profile:

```sh
cargo bench --profile tracer-release -p shm_gen_cache --bench sgc_bench --no-run
```

`--no-run` prints the absolute path of the binary under the workspace
target directory (the repository root's `target/`, not one inside this
crate), e.g. `../target/tracer-release/deps/sgc_bench-<hash>` relative to
`shm_gen_cache/`. It can be run directly or handed to `ab_bins.py` (run
from `shm_gen_cache/`):

```sh
uv run bench/ab_bins.py --ref-bin <cmake-build>/cpp/sgc_bench \
  --cur-bin ../target/tracer-release/deps/sgc_bench-<hash> --quick
```

## What is measured

The configuration is a `Config` resolved at startup and passed as
`RuntimeParams`, with the occupancy mode (`Estimated` for this
configuration) selected from the resolved values at run time: the same
monomorphisation the C API (`src/ffi.rs`) uses, so the numbers are those of
a production embedding, not of a `static_config!` whose parameters fold to
constants. Each worker thread registers its own `ParticipantLock`.

Unlike the C++ harness's Rust backend, which calls the staticlib through
the C API (one out-of-line call per operation), here the cache code is
compiled into the bench with LTO and may be inlined into the timed loop, as
it would be in a Rust caller.

## Layout

| module | contents |
|---|---|
| `sgc_bench.rs` | `main`, occupancy-mode dispatch |
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

## Differences from the C++ harness

None in workloads, data or results: for one thread, `hit%`, `ins%`,
`~hit%`, `~prm%` and `rot/rep` equal the C++ harness's exactly, and the
model columns (pure functions of the streams) match for every thread count.
Mechanics that differ:

- The header's `backend=` reads `rust-api` (C++ harness: `cpp` / `rust`);
  `best_effort_promotion=n/a`, as for the C++ harness's Rust backend (the
  library has no such option).
- The coordinating thread sleeps between stages with `thread::park` (woken
  by the last worker to arrive) instead of a C++20 atomic wait. It does not
  take part in the timing: a repetition ends at the latest worker's own end
  timestamp.
- Workers return their counters and streams on join instead of writing
  shared arrays; stage end timestamps are relaxed atomics.
- `--bench`, which `cargo bench` appends, is accepted and ignored.
