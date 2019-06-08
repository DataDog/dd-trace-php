# Datadog PHP Tracer Benchmarking & Profiling

## Goals

- [x] Easy-to-install Docker container to start benchmarking & profiling with minimal setup
- [x] Easily compare benchmark results between any version of the PHP tracer
  + [x] Time
  + [ ] Memory
  + [ ] CPU
- [x] Easily benchmark any supported PHP version
- [ ] Easily profile the PHP tracer
- [ ] Easy to compare benchmark metrics via Datadog UI dashboard
- [ ] Integrate benchmarks into CI pipeline

## Setup

From the console, `cd` into the **benchmarking** folder and build the containers. And go get some coffee because it's going to build every supported PHP version and all the dependencies.

> **TODO:** Let's consider eventually adding this to the [Datadog Docker library](https://github.com/DataDog/docker-library).

```bash
$ docker-compose build
```

Then run the Docker containers.

```bash
$ docker-compose up -d
```

The benchmark commands can be run from the **php** container. To access `bash` run:

```bash
$ docker-compose exec php bash
```

## Running Benchmarks

Once inside the **php** container, run the `./benchmarks` script to see a list of options.

```bash
$ ./benchmark
```

The `run` command will run all the benchmark scripts in the **benchmark-scripts** directory. To list all the options for the `run` command, run it with the `--help` flag:

```bash
$ ./benchmark run --help
```

The target PHP version is a required parameter followed by an optional list of tracer versions. The following command will run the benchmark scripts for versions **0.20.0** and **0.27.2** of the PHP tracer against PHP 7.3.

```bash
$ ./benchmark run 7.3 0.20.0 0.27.2
```

A special version called `local` will compile the local copy of the PHP tracer in the container.

```bash
$ ./benchmark run 7.3 0.27.2 local
```

Each listed version of the tracer will be downloaded and compiled into the container separately. On consecutive runs, the already-compiled tracer versions will not be recompiled unless supplying the `--force-recompile=<tracer version>` (or `-f<tracer version>` for short). To recompile all the versions, use `-fall`.

Any changes made to the `local` tracer version (either at the PHP or C-level code), a recompile is necessary to see the changes.

```bash
$ ./benchmark run 7.3 0.20.0 0.27.2 local --force-recompile=local # Recompile only the local version
$ ./benchmark run 7.3 0.20.0 0.27.2 local -flocal # Short syntax
$ ./benchmark run 7.3 0.20.0 0.27.2 local -f0.20.0 # Recompile version 0.20.0
$ ./benchmark run 7.3 0.20.0 0.27.2 local -fall # Recompile all listed versions
```

To see additional debug info, increase the verbosity level with `-v`.

```bash
$ ./benchmark run 7.0 0.16.0 0.17.0 0.18.0 -v
```

The output from compiling the extension versions is hidden behind the very-verbose flag, `-vv`.

```bash
$ ./benchmark run 7.1 0.17.0 0.20.0 -vv
```

## Adding Benchmarks

Create a new directory in the **benchmark-scripts** directory and add some plain-old PHP scrips to benchmark.

If you need to configure the env vars or INI settings, copy the **config.template.php** file into the new directory, name it **config.php**, and edit it as desired.

## Send benchmark results to the Datadog UI (not implemented)

> **TODO:** Make this work.

Make sure you have a valid Datadog API key env set in the `DATADOG_API_KEY` env var.

```bash
$ echo $DATADOG_API_KEY
```

If the env var is empty, you can [grab an API key from your settings](https://app.datadoghq.com/account/settings#api) and add it to your **~/.bashrc** file.

```bash
$ echo 'export DATADOG_API_KEY="<my-api-key>"' | tee -a ~/.bashrc && source ~/.bashrc
```

Then stop and start the containers again.

```bash
$ docker-compose down && docker-compose up -d
```

## Benchmarking TODO

- [ ] ~~[PhpBench](https://github.com/phpbench/phpbench)~~ After much investigation, PhpBench wasn't able to offer the flexibility to benchmark multiple tracers across multiple PHP versions going all the way back to PHP 5.4. Therefore it is being removed as a consideration as a benchmarking implementation.
- [ ] Custom benchmarking script from Playtika

## Profiling TODO

- [ ] Xdebug cachegrind files
- [ ] New Etsy profiler
- [ ] Tideways XHProf?
- [ ] Blackfire.io?
