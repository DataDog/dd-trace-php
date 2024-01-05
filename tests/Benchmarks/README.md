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

## Run the benchmarks with the Native Profiler

Running the benchmarks with the [Native Profiler](https://github.com/DataDog/ddprof) is a good way to get a better understanding of the performance of the code.

To run the benchmarks with the Native Profiler, the procedure is a bit different than the one described above.

### Requirements

- Ensure that the container is able to change `perf_event_paranoid`. You can simply run the container in privileged mode by uncommenting `# privileged: true` in the `base_php_service` from [docker-compose.yml](../../docker-compose.yml) file.
- Ensure that the `agent` service will be given a valid API key by setting the `DATADOG_API_KEY` environment variable
  - Depending on your API key's origin, this service may require a value for `DD_SITE` (e.g., `DD_SITE=datadoghq.eu` for the EU site)

### Run the benchmarks
Then you can leverage the Native Profiler by using [run_with_native_profiler.sh](../../benchmark/run_with_native_profiler.sh) script.

For instance, if you want to run the `benchTelemetryParsing` subject five times using the Native Profiler, you can run the following command:

```bash
./run_with_native_profiler.sh --scenario benchTelemetryParsing -n 5
```

### Options
```
Usage: ./run_with_native_profiler.sh [options]
Options:
  -s, --scenario <scenario>  The scenario to run (e.g., benchTelemetryParsing, LaravelBench). Defaults to all scenarios (.)
  -t, --style <style>        The style of benchmark to run (base, opcache). Defaults to base
  -n, --n <n>                The number of times to run the benchmark. Defaults to 1
  -w, --without-dependencies If set, the dependencies will not be installed.
  --split <true|false>       Whether to split the results into multiple profiles. Defaults to true. Only applies when all scenarios are run at once.
Example: ./run_with_native_profiler.sh --scenario benchTelemetryParsing --style base -n 5 -w
Example: ./run_with_native_profiler.sh  --style opcache -n 5 -w --split false
```

### Results
The data from the native profiler will be available in the `/profiling` section of the Datadog UI!

The benchmark subjects are split by default, and the corresponding services are named `<scenario>_<style>_<timestamp>`. For instance, if you run the `benchTelemetryParsing` scenario with the `base` style, the service will be named `benchtelemetryparsing_base_<timestamp>`.
