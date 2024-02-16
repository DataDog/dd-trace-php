# Microbenchmarks

This directory contains the request **startup** and **shutdown** microbenchmarks.

The benchmarks uses [Google Benchmark](https://github.com/google/benchmark), whose source is included as a git submodule under `./google-benchmark`.

## How to run benchmarks

First refer to the [CONTRIBUTING.md](../../CONTRIBUTING.md) file to setup the environment.

Then you can run the benchmarks with the following command from the root of the repository:

```bash
make benchmarks_tea
```

## How to add a new benchmark

The benchmarks are located in the [benchmark.cc](./benchmark.cc) file and are written using [Google Benchmark](https://github.com/google/benchmark) (v1.8.3).

To add a new benchmark, create a new function in the [benchmark.cc](./benchmark.cc) file. Please, refer to the [User Guide](https://github.com/google/benchmark/blob/main/docs/user_guide.md) for more information.

## Results

The results of the benchmark are stored under the [reports](./reports) folder.
