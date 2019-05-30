# Datadog PHP Tracer Benchmarking & Profiling

## Goals

- [ ] Easy-to-install Docker container to start benchmarking & profiling with minimal setup
- [ ] Easily switch between any commit or tag of the PHP tracer
- [ ] Easily switch between any supported PHP version
- [ ] Easy to compare benchmark metrics via Datadog UI dashboard
- [ ] Integrate benchmarks into CI pipeline

## Setup

Make sure you have a valid Datadog API key env set in the `DATADOG_API_KEY` env var.

```bash
$ echo $DATADOG_API_KEY
```

If the env var is empty, you can [grab an API key from your settings](https://app.datadoghq.com/account/settings#api) and add it to your **~/.bashrc** file.

```bash
$ echo 'export DATADOG_API_KEY="<my-api-key>"' | tee -a ~/.bashrc && source ~/.bashrc
```

Then just run the Docker containers.

```bash
$ docker-compose up -d
```

## Benchmarking TODO

- [ ] [PhpBench](https://github.com/phpbench/phpbench)
- [ ] Custom benchmarking script from Playtika

## Profiling TODO

- [ ] Xdebug cachegrind files
- [ ] New Etsy profiler
- [ ] Tideways XHProf?
- [ ] Blackfire.io?
