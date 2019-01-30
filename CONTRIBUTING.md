# Contributing to dd-trace-php

As an open-source project we welcome contributions of many forms, but due to the experimental pre-beta nature of this repository, you should [reach out to us](https://github.com/DataDog/dd-trace-php/issues) before starting work on any major code changes. This will ensure we avoid duplicating work, or that your code can't be merged due to a rapidly changing base.

## Getting set up with Docker

The easiest way to get the development environment set up is to install [Docker](https://www.docker.com/) and run the following [Docker Compose](https://docs.docker.com/compose/) command from the project root to start the containers.

```bash
$ docker-compose up -d
```

This will run the [preconfigured docker images](https://hub.docker.com/r/datadog/docker-library/) that we provide for the different PHP versions.

- PHP 5.6: `datadog/docker-library:ddtrace_php_5_6`
- PHP 7.0: `datadog/docker-library:ddtrace_php_7_0`
- PHP 7.1: `datadog/docker-library:ddtrace_php_7_1`
- PHP 7.2: `datadog/docker-library:ddtrace_php_7_2`

To access a `bash` from the PHP 7.2 container, run the following:

```bash
$ docker-compose run --rm 7.2 bash
```

Once inside the container, install the dependencies with Composer.

```bash
$ composer install
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
For help, check out the documentation at https://github.com/DataDog/dd-trace-php/blob/master/README.md#getting-started
(c) Datadog 2018

Datadog tracing support => enabled
Version => 0.7.0-beta
```

When you're done with development, you can stop and remove the containers with the following:

```bash
$ docker-compose down
```

### Running the tests

In order to run all the tracer tests:

    # Run all tests for for php 5.6
    $ composer test-all-56

    # Run all tests for for php 7.0
    $ composer test-all-70

    # Run all tests for for php 7.1
    $ composer test-all-71

    # Run all tests for for php 7.2
    $ composer test-all-72

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

### Static Analyzer

The [PHPStan static analyzer](https://github.com/phpstan/phpstan) is part of the build checks when submitting a PR. To ensure your contribution passes the static analyzer, run the following:

```bash
$ composer static-analyze
```

> **Note:** The static analyzer only works on PHP 7.1 builds and greater.

## Sending a pull request (PR)

There are a number of checks that are run automatically with [CircleCI](https://circleci.com/gh/DataDog/dd-trace-php/tree/master) when a PR is submitted. To ensure your PHP code changes pass the CircleCI checks, make sure to run all the same checks before submitting a PR.

```bash
$ composer test && composer lint && composer static-analyze
```

## Changelog

All notable changes to this project will be documented in `CHANGELOG.md` file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

### Changelog entry format

Changelog entry should try to form a coherent sentence with the heading e.g,:

```md
### Added
- integration
```

Changelog entry must link to relevant PR(s) via ```#reference``` e.g. ```new integration #124, #122```

Changelog entry might mention PR author(s) via ```@mention```- especially when they are not a member of the DataDog team.

Changelog entry should start with lowercase or preferably, a specific integration name it concerns e.g., Laravel.

### Example Changelog

```md
## [Unreleased]
### Added
- Laravel integration #124 (@pr_author)
### Fixed
- Laravel integration breaking bug #123
### Changed
- Laravel integration documentation #111
### Removed
- support for PHP 5.3 #2

## [0.0.1] - 2018-01-01
### Added
- support for PHP 5.3 #1

[Unreleased]: https://github.com/DataDog/dd-trace-php/compare/0.0.1...HEAD
[0.0.1]: https://github.com/DataDog/dd-trace-php/compare/0.0.0...0.0.1
```
