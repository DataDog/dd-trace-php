# A/B benchmark: C++ shm_gen_cache vs this Rust port

One harness, two libraries. `sgc_bench.cpp` is a copy of
`~/repos/shm_gen_cache/bench/sgc_bench.cpp` (C++ project at `7429fa7`)
whose only change is its library-binding block (marked `[dd-trace-php]`):

- `SGC_BENCH_BACKEND_RUST=0`: binds the C++ library exactly as the original
  bench does (`sgc::cache<config>`, `participant_lock`, `output_buffer`).
- `SGC_BENCH_BACKEND_RUST=1`: binds this crate's staticlib through the C API
  in `../shm_gen_cache.h`, via the inline shim in `rust_backend.hpp`, which
  reproduces the few C++ calls the harness makes (`initialize`,
  `register_participant`, `insert`, `lookup`, RAII unregister).

Workloads, data, streams, timing, the reference model and the JSON output
are byte-identical between the two binaries, so the comparison measures only
the library. The Rust side receives the configuration at **run time**
(`ddog_sgc_Config`), the production shape; the C++ side has it as a template
argument. `ddog_sgc_cache_mapping_size` and `ddog_sgc_cache_init_in` let
the Rust bench keep the harness's own mapping, so `--huge-pages` (Linux
default: private THP mapping) works for both backends.

A native port of the same harness, driving the crate through its Rust API
(`cargo bench -p shm_gen_cache --bench sgc_bench`), is in
[`../benches/`](../benches/README.md); its binary produces the same table
and JSON, so it can be either side of `ab_bins.py`.

Files:

| file | what |
|---|---|
| `sgc_bench.cpp` | the harness (copy + backend switch) |
| `rust_backend.hpp` | C++ surface of the harness on top of the C API |
| `CMakeLists.txt` | `SGC_BENCH_BACKEND=cpp\|rust` |
| `ab_bins.py` | A/B of two prebuilt binaries: interleaved rounds, noise model, verdicts |

## Building

Both backends must use the same C++ compiler. The C++ library needs clang
on Linux (GCC 15 rejects it). The Rust side is built by CMake with `cargo
build -p shm_gen_cache --profile tracer-release` into
`<build>/cargo` (the profile: fat LTO, one codegen unit, `panic=abort`).

macOS (Apple clang):

```sh
cd ~/repos/dd-trace-php/shm_gen_cache
cmake -S bench -B /tmp/sgc-ab/cpp  -DSGC_BENCH_BACKEND=cpp
cmake -S bench -B /tmp/sgc-ab/rust -DSGC_BENCH_BACKEND=rust
cmake --build /tmp/sgc-ab/cpp -j && cmake --build /tmp/sgc-ab/rust -j
```

Linux (clang 21; the C++ tree defaults to `~/repos/shm_gen_cache`):

```sh
cmake -S bench -B ~/sgc-ab/cpp -DSGC_BENCH_BACKEND=cpp \
  -DCMAKE_CXX_COMPILER=clang++-21 -DSGC_SOURCE_DIR=/path/to/shm_gen_cache
cmake -S bench -B ~/sgc-ab/rust -DSGC_BENCH_BACKEND=rust \
  -DCMAKE_CXX_COMPILER=clang++-21
cmake --build ~/sgc-ab/cpp -j$(nproc) && cmake --build ~/sgc-ab/rust -j$(nproc)
```

Build type defaults to `RelWithDebInfo` (`-O2 -g`). The harness gets the
library's dialect for both backends (`-fno-exceptions -fno-rtti
-fno-threadsafe-statics -fno-unwind-tables -fno-asynchronous-unwind-tables`;
the original `#error`s without them).

Cross-language LTO is not needed: without it a lookup or an insert costs
one direct call into the staticlib, and the C++ library's
`cache::lookup`/`store` are out-of-line calls too. Measured on Linux with
full LTO on both sides, it made the Rust side relatively *slower* (geomean
0.910 vs 0.946), so the build has no LTO option.

## Running

Correctness smoke first (aborts on a bad value or failed registration; the
measured `hit%` of t1 scenarios must match the model's `~hit%` as closely
as the C++ binary does):

```sh
<build>/rust/sgc_bench --quick --verify
```

A/B (ref = C++, cur = Rust; `speedup` < 1 means Rust is slower; 5 rounds,
alternating order):

```sh
uv run bench/ab_bins.py --ref-bin <build>/cpp/sgc_bench \
  --cur-bin <build>/rust/sgc_bench --json ab.json
# Linux, shared 4 KiB-page mapping (what a multi-process deployment gets):
uv run bench/ab_bins.py ... --bench-args=--no-huge-pages
```

`--bench-args` must use the `=` form when its value starts with `--`.

The Rust port of the harness takes either side too; its build and A/B
commands are in [`../benches/README.md`](../benches/README.md).

## Results (2026-10-07)

### C++ harness: C++ library vs Rust library (C API)

Geomean of per-scenario speedups (Rust / C++ throughput), full suite, 5
rounds, `RelWithDebInfo`, no LTO. ubuntu-vm: AMD Ryzen 9 9950X (Zen 5), 32
vCPUs, clang 21.1.8; macOS: Apple M-series, Apple clang 17 (machine under
unrelated load, so noisier).

| run | ALL | mixed | lookup_hit | lookup_miss | insert_new |
|---|---|---|---|---|---|
| Linux, THP (default) | **0.952** | 0.962 | 0.926 | 0.917 | 0.983 |
| Linux, `--no-huge-pages` | **0.965** | 0.978 | 0.930 | 0.911 | 1.010 |
| macOS (`isb` spin hint) | **0.978** | 0.974 | 1.020 | 0.936 | 0.968 |

So the production build (run-time configuration, plain staticlib) is ~5%
slower than the C++ library on Linux and ~2% on macOS, well inside the 20%
budget. The 4- and 8-thread insert-heavy scenarios (`insert_new`,
`mixed/1Mi`, `mixed/64Ki/s0.8`) are bimodal run to run on this VM for both
backends; their per-scenario verdicts are mostly "within noise".

### Rust bench (Rust API) vs this harness

Same settings; cur = the Rust bench (`tracer-release`). (b) ref = this
harness with the C++ library: the port as a whole. (a) ref = this harness
with the Rust library through the C API: the same cache code on both
sides, so only the harness and the call boundary differ.

| run | ALL | mixed | lookup_hit | lookup_miss | insert_new |
|---|---|---|---|---|---|
| (b) Linux, THP | **0.966** | 0.958 | 0.943 | 0.982 | 1.043 |
| (b) Linux, `--no-huge-pages` | **0.964** | 0.962 | 0.937 | 0.963 | 1.034 |
| (b) macOS, two runs | **0.948 / 0.977** | 0.952 / 0.974 | 0.980 / 0.986 | 0.945 / 0.943 | 0.868 / 1.008 |
| (a) Linux, THP | 1.205 | 1.338 | 1.007 | 1.067 | 1.038 |
| (a) Linux, `--no-huge-pages` | 1.192 | 1.321 | 0.999 | 1.061 | 1.028 |
| (a) Linux, THP, C API side `-align-loops=64` | **1.017** | 1.007 | 1.008 | 1.087 | 1.027 |
| (a) Linux, no huge pages, same | **1.013** | 1.008 | 0.992 | 1.069 | 1.031 |
| (a) macOS, two runs | **0.992 / 0.983** | 0.984 / 0.960 | 0.986 / 0.979 | 1.025 / 0.994 | 1.018 / 1.126 |

The Rust bench therefore measures the port at 3-5% below the C++ library,
as this harness does. Against the C API build it is at parity, with
`lookup_miss` 7-9% faster on Linux: the cache code is compiled into the
bench and can be inlined, with no call per operation. macOS run 1 of (b)
had a load spike that hit `insert_new` and `mixed/1Mi`.

The unaligned Linux (a) rows are a code-layout artifact of that C API
build, not a harness difference; rebuilding the staticlib with
`RUSTFLAGS=-Cllvm-args=-align-loops=64` removes it.
