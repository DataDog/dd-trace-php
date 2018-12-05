# Benchmarking dd-trace-php

Benchmarking the `dd-trace-php` project is done with [PhpBench](https://github.com/phpbench/phpbench).

## Running the benchmarks

It's best to run the benchmarks inside one of the Docker containers. To run the PHP 7.2 container for example, run the following:

```bash
$ git checkout benchmarking \
  && docker-compose up -d \
  && docker-compose run --rm 7.2 bash
```

Once inside the container, install the ddtrace extension and then update the Composer dependencies to make sure PhpBench is installed.

```bash
$ composer install-ext && composer update
```

Now you should be able to run the benchmarks.

```bash
$ ./vendor/bin/phpbench run --revs=1000 --report=integrations
```

## Running specific benchmarks

If you want to run just the Memcached integration benchmarks for example, you could run the following:

```bash
$ ./vendor/bin/phpbench run --revs=1000 --report=integrations benchmarks/Integrations/Memcached/
```

## Adding benchmarks

The benchmarks are located in the `benchmarks/` folder. Read the [official documentation](https://phpbench.readthedocs.io/en/latest/writing-benchmarks.html) on how to write benchmarks.

## Configuration

The benchmarking configuration file is located at `phpbench.json`. Read the [official documentation](https://phpbench.readthedocs.io/en/latest/configuration.html) on the configuration file.
