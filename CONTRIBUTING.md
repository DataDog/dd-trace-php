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
$ docker-compose run --rm 5.4
# For 5.6
$ docker-compose run --rm 5.6
# For 7.0
$ docker-compose run --rm 7.0
# For 7.1
$ docker-compose run --rm 7.1
# For 7.2
$ docker-compose run --rm 7.2
# For 7.3
$ docker-compose run --rm 7.3
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
(c) Datadog 2019

Datadog tracing support => enabled
Version => 1.0.0-nightly

Directive => Local Value => Master Value
ddtrace.disable => Off => Off
ddtrace.internal_blacklisted_modules_list => ... => ...,
ddtrace.request_init_hook => no value => no value
ddtrace.strict_mode => Off => Off
```

When you're done with development, you can stop and remove the containers with the following:

```bash
$ docker-compose down -v
```

### Running the tests

In order to run all the tracer tests:

    # Run all tests for for php 5.4
    $ composer test-all-54

    # Run all tests for for php 5.6
    $ composer test-all-56

    # Run all tests for for php 7.0
    $ composer test-all-70

    # Run all tests for for php 7.1
    $ composer test-all-71

    # Run all tests for for php 7.2
    $ composer test-all-72

    # Run all tests for for php 7.3
    $ composer test-all-73

    # Run all tests for for php 7.4
    $ composer test-all-74

> **Note:** The `composer test` command is a wrapper around `phpunit`, so you can use all the common [options](https://phpunit.de/manual/5.7/en/textui.html#textui.clioptions) that you would with `phpunit`. However you need to prepend the options list with the additional `--` dashes that `composer` requires:

    # Run only unit tests
    $ composer test -- --testsuite=unit

    # Run only integration tests
    $ composer test -- --testsuite=integration

    # Run only library integrations tests for php 5.6
    $ composer test-integrations-56

    # Run only library integrations tests for php 7.0
    $ composer test-integrations-70

    # Run only library integrations tests for php 7.1
    $ composer test-integrations-71

    # Run only library integrations tests for php 7.2
    $ composer test-integrations-72

    # Run only library integrations tests for php 7.3
    $ composer test-integrations-73

    # Run only library integrations tests for php 7.3
    $ composer test-integrations-73

Testing individual integrations with libraries requires an additional step, as there are different scenarios where you want to test
a specific integration. You can find available scenarios in `composer.json` at key `extras.scenarios`.

As an example, in order to run Guzzle tests with Guzzle v5 library, run:

    # Only the first time, to create all the different scenarios
    $ composer scenario:update

    # Activate the specific scenario
    $ composer scenario guzzle5

    # Run only guzzle tests
    $ composer test -- tests/Integrations/Guzzle/

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
