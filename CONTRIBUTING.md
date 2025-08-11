# Contributing to dd-trace-php

As an open-source project we welcome contributions of many forms, but you should [reach out to us](https://github.com/DataDog/dd-trace-php/issues) before starting work on any major code changes. This will ensure we avoid duplicating work, or that your code can't be merged due to a rapidly changing base.

## Project initialization

The project should be cloned in the php source code:
```bash
git clone https://github.com/php/php-src.git
cd php-src/ext
git clone https://github.com/DataDog/dd-trace-php.git
```

The project uses git submodules to include the [datadog shared library](https://github.com/DataDog/libdatadog). From the project root:

```
git submodule update --init --recursive
```

To integrate new tracing and profiling features from the datadog shared library please refer to this [guide](https://github.com/DataDog/dd-trace-php/blob/master/LIBDATADOG.md).

## Getting set up with Docker

The easiest way to get the development environment set up is to install [Docker](https://www.docker.com/) and
[Docker Compose](https://docs.docker.com/compose/).

## Developing and testing locally

### PHP linting

The PHP tracer conforms to the [PSR-2 coding style guide](https://www.php-fig.org/psr/psr-2/). The code style is checked with [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) which can be invoked with the following command:

```bash
$ composer lint
```

To try to automatically fix the code style, you can run:

```bash
$ composer fix-lint
```

### Testing

#### Start your container

While tests in CI run on all php versions, you typically develop on one version locally. Currently the latest local
dev environment we support is `8.3`.

Ensure that docker has at least 12 GB of RAM available, otherwise composer may run out of memory or the tmpfs volume
used to build the extension may run out of space.

Execute one the following commands from your command line, this will bring up all required services:

```bash
# For 7.0
$ docker compose run --rm 7.0-bookworm bash
# For 7.1
$ docker compose run --rm 7.1-bookworm bash
# For 7.2
$ docker compose run --rm 7.2-bookworm bash
# For 7.3
$ docker compose run --rm 7.3-bookworm bash
# For 7.4
$ docker compose run --rm 7.4-bookworm bash
# For 8.0
$ docker compose run --rm 8.0-bookworm bash
# For 8.1
$ docker compose run --rm 8.1-bookworm bash
# For 8.2
$ docker compose run --rm 8.2-bookworm bash
# For 8.3
$ docker compose run --rm 8.3-bookworm bash
# For 8.4
$ docker compose run --rm 8.4-bookworm bash
```

> :memo: **Note:** To run the container in debug mode, pass `docker-compose` an environment variable: `DD_TRACE_DOCKER_DEBUG=1`, eg:

```bash
$ docker compose run -e DD_TRACE_DOCKER_DEBUG=1 --rm 8.3-bookworm bash
```

#### Set up the container

Once inside the container, update dependencies with Composer.

```bash
$ composer update
```

Then install the `ddtrace` extension.

```bash
$ composer install-ext
```
or
```bash
$ sudo make install install_ini
```

> :memo: **Note:** You'll need to run the above `install-ext` command to install the `ddtrace` extension every time you access the container's bash for the first time.

You can check that the extension was installed properly.

```bash
$ php --ri=ddtrace
```

You should see output similar to the following:

```
ddtrace


Datadog PHP tracer extension
For help, check out the documentation at https://docs.datadoghq.com/tracing/languages/php/
(c) Datadog 2020

Datadog tracing support => enabled
Version => 1.0.0-nightly
DATADOG TRACER CONFIGURATION => ...
```

#### Run the tests

First you need to update composer's dependecies in `./tests` folder:

    $ make composer_tests_update

> :memo: **Note:** To disable reliance on the generated files during development and testing, set the following environment variable:
>
> `export DD_AUTOLOAD_NO_COMPILE=true`

Then you can run tests:

    # Run all tests
    $ make test_all

    # Run unit tests (tests/Unit folder)
    $ make test_unit

    # Run integration tests (tests/Integration folder)
    $ make test_integration

    # Run auto-instrumentation tests (tests/AutoInstrumentation folder)
    $ make test_auto_instrumentation

    # Run composer integration tests (tests/Composer folder)
    $ make test_composer

    # Run distributed tracing tests (tests/DistributedTracing folder)
    $ make test_distributed_tracing

    # Run library integrations tests (tests/Integrations folder)
    $ make test_integrations

    # Run web frameworks integrations tests
    $ make test_web

    # Run C Tests (the ones in tests/ext)
    $ make test_c

    # Run PHP benchmarks
    $ make benchmarks

    # Run OPcache PHP benchmarks
    $ make benchmarks_opcache

To run a specific C Tests, you can use the TESTS parameter:
```bash
$ make test_c TESTS=tests/ext/<pathToTest>
```
To run a specific library integration tests, you can use:
``` bash
$ make test_integrations_<library>
```
You can even specify a singular test with the FILTER parameter, for example
```bash
$ make test_integrations_curl FILTER=testLoad200UrlOnInit
```

In order to run the `phpt` tests for the php extension:

```bash
$ composer test-ext
```

#### Teardown the environment

When you're done with development, you can stop and remove the containers with the following:

```bash
$ docker-compose down -v
```

#### Snapshot Tests

[Snapshot testing](https://github.com/DataDog/dd-apm-test-agent#snapshot-testing) is utilized in some tests to validate
the output of the tracer. To update the snapshots when modifying the tracer's output, follow these steps:
1. Delete the previous snapshot file located in tests/snapshots that corresponds to the relevant tests.
2. Run the tests again after updating the library.
3. A new snapshot file will be automatically generated.

When creating new tests that utilize snapshots, the initial run will generate a snapshot file in the `tests/snapshots`
directory. For example, if the test is `DDTrace\Tests\Integrations\Framework\VX\TestClass::testFunction()`,
the corresponding snapshot file would be `tests.integrations.framework.vx.test_class.test_function`. Subsequent test runs
will compare the tracer's output with the generated snapshot file.

Always ensure that the generated snapshot file contains the expected output before committing it. It is important to
review the snapshot file to maintain the accuracy of the tests.

## Sending a pull request (PR)

There are a number of checks that are run automatically with [CircleCI](https://circleci.com/gh/DataDog/dd-trace-php/tree/master) when a PR is submitted. To ensure your PHP code changes pass the CircleCI checks, make sure to run all the same checks before submitting a PR.

```bash
$ composer lint && test-all-<php-version>
```
