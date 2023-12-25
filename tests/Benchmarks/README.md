# Benchmarks

## How to run benchmarks

First refer to the [CONTRIBUTING.md](../../CONTRIBUTING.md) file to setup the environment.

Then you can run the benchmarks with the following command from the root of the repository:

```bash
make benchmarks
```

or if you want to run the benchmarks with OPcache enabled:

```bash
make benchmarks_opcache
```

## How to add a new benchmark

The benchmarks are located in the [tests/Benchmarks](.) folder and are written using [PHPBench](https://github.com/phpbench/phpbench).

To add a new benchmark, create a new file under the [tests/Benchmarks](.) folder. In order for the benchmarks to be executed, the file name and class name must be suffixed with `Bench`, and each benchmark method must be prefixed with `bench`.

Then you can write your benchmark using the [PHPBench](https://phpbench.readthedocs.io/en/latest/quick-start.html#create-a-benchmark) syntax.

## How to run a single benchmark

You can run a single benchmark with the following command:

```bash
make benchmarks FILTER=<benchmark_name>
```

## How to run a single benchmark suite

You can run a single benchmark suite with the following command:

```bash
make benchmarks FILTER=<benchmark_class_name>
```

## Results

The results of the benchmarks, as defined in the [phpbench.json](../phpbench.json) (resp. [phpbench-opcache.json](../phpbench-opcache.json)) file, are store in the [tests/Benchmarks/reports](./reports) folder.
