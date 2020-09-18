# Contributing to dd-trace-php

As an open-source project we welcome contributions of many forms, but due to the experimental pre-beta nature of this repository, you should [reach out to us](https://github.com/DataDog/dd-trace-php/issues) before starting work on any major code changes. This will ensure we avoid duplicating work, or that your code can't be merged due to a rapidly changing base.

## Getting set up with Docker

The easiest way to get the development environment set up is to install [Docker](https://www.docker.com/) and
[Docker Compose](https://docs.docker.com/compose/).

## Developing and testing locally

While tests in CI run on all php versions, you typically develop on one version locally. Currently the latest local
dev environment we support is `7.3`.

Execute one the following commands from your command line, this will bring up all required services:

```bash
# For 5.4
$ docker-compose run --rm 5.4-debug-buster bash
# For 5.5
$ docker-compose run --rm 5.5-debug-buster bash
# For 5.6
$ docker-compose run --rm 5.6-debug-buster bash
# For 7.0
$ docker-compose run --rm 7.0-debug-buster bash
# For 7.1
$ docker-compose run --rm 7.1-debug-buster bash
# For 7.2
$ docker-compose run --rm 7.2-debug-buster bash
# For 7.3
$ docker-compose run --rm 7.3-debug-buster bash
# For 7.4
$ docker-compose run --rm 7.4-debug-buster bash
```

Once inside the container, update dependencies with Composer.

```bash
$ composer update
```

Then install the `ddtrace` extension.

```bash
$ composer install-ext
```

> **Note:** You'll need to run the above `install-ext` command to install the `ddtrace` extension every time you access the container's bash for the first time.

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

When you're done with development, you can stop and remove the containers with the following:

```bash
$ docker-compose down -v
```

### Running the tests

First you need to update composer's dependecies in `./tests` folder:

    $ make composer_tests_update

Then you can run tests:

    # Run all tests
    $ make test_all

    # Run unit tests
    $ make test_unit

    # Run integration tests
    $ make test_integration

    # Run auto-instrumentation tests
    $ make test_auto_instrumentation

    # Run composer integration tests
    $ make test_composer

    # Run distributed tracing tests
    $ make test_distributed_tracing

    # Run library integrations tests
    $ make test_integrations

    # Run web frameworks integrations tests
    $ make test_web

In order to run the `phpt` tests for the php extension:

```bash
$ composer test-ext
```

### PHP linting

The PHP tracer conforms to the [PSR-2 coding style guide](https://www.php-fig.org/psr/psr-2/). The code style is checked with [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) which can be invoked with the following command:

```bash
$ composer lint
```

To try to automatically fix the code style, you can run:

```bash
$ composer fix-lint
```

## Sending a pull request (PR)

There are a number of checks that are run automatically with [CircleCI](https://circleci.com/gh/DataDog/dd-trace-php/tree/master) when a PR is submitted. To ensure your PHP code changes pass the CircleCI checks, make sure to run all the same checks before submitting a PR.

```bash
$ composer composer lint && test-all-<php-version>
```
