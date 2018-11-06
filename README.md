# DD Trace PHP

[![CircleCI](https://circleci.com/gh/DataDog/dd-trace-php/tree/master.svg?style=svg)](https://circleci.com/gh/DataDog/dd-trace-php/tree/master)
[![OpenTracing Badge](https://img.shields.io/badge/OpenTracing-enabled-blue.svg)](http://opentracing.io)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%205.6-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-BSD%203--Clause-blue.svg)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/datadog/dd-trace.svg)](https://packagist.org/packages/datadog/dd-trace)
[![Total Downloads](https://img.shields.io/packagist/dt/datadog/dd-trace.svg)](https://packagist.org/packages/datadog/dd-trace)

PHP Tracer

This is Beta software. We do not recommend using it in production yet.

## Getting Started

For a basic product overview, check out our [setup documentation](https://docs.datadoghq.com/tracing/setup/php/).

For installation, configuration, and and details about using the API, check out our [API documentation](docs/getting_started.md).

For descriptions of terminology used in APM, take a look at the [official documentation](https://docs.datadoghq.com/tracing/visualization/).

## Installation

```
composer require datadog/dd-trace
```

## Requirements

- PHP 7.0 or later

## Usage

In order to be familiar with tracing elements it is recommended to read the [OpenTracing specification](https://github.com/opentracing/specification/blob/master/specification.md).

### Using the tracer

To start using the DataDog Tracer with the OpenTracing API, you should first initialize the tracer:

```php
use DDTrace\Tracer;
use OpenTracing\GlobalTracer;

// Creates a tracer with default transport and default propagators
$tracer = new Tracer();

// Sets a global tracer (singleton). Ideally tracer should be
// injected as a dependency
GlobalTracer::set($tracer);

$application->run();

// Flushes traces to agent.
register_shutdown_function(function() {
    GlobalTracer::get()->flush();
});
```

PHP as a request scoped language has no simple means to pass the collected spans data to a background process without blocking the main request thread/process. It is mandatory to execute the `Tracer::flush()` after the response is served to the client by using [`register_shutdown_function`](http://php.net/manual/en/function.register-shutdown-function.php).

### Advanced configuration

Transport can be customized by the config parameters:

```php
use DDTrace\Encoders\Json;
use DDTrace\Transport\Http;

$transport = new Http(
    new Json(),
    $logger,
    [
        'endpoint' => 'http://localhost:8126/v0.3/traces', // Agent endpoint
    ]
);
```

Tracer can be customized by the config settings:

```php
use DDTrace\Tracer;
use OpenTracing\Formats;

// Config for tracer
$config = [
    'service_name' => 'my_service', // The name of the service.
    'enabled' => true, // If tracer is not enabled, all spans will be created as noop.
    'global_tags' => ['host' => 'hostname'], // Set of tags being added to every span.
];

$tracer = new Tracer(
    $transport,
    [ Formats\TEXT_MAP => $textMap ],
    $config
);
```

### Creating Spans

- [Starting a root span](https://github.com/opentracing/opentracing-php#starting-an-empty-trace-by-creating-a-root-span)
- [Starting a span for a given request](https://github.com/opentracing/opentracing-php#creating-a-span-given-an-existing-request)
- [Active span and scope manager](https://github.com/opentracing/opentracing-php#active-spans-and-scope-manager)
  - [Creating a child span assigning parent manually](https://github.com/opentracing/opentracing-php#creating-a-child-span-assigning-parent-manually)
  - [Creating a child span using automatic active span management](https://github.com/opentracing/opentracing-php#creating-a-child-span-using-automatic-active-span-management)
- [Using span options](https://github.com/opentracing/opentracing-php#using-span-options)

### Propagation of context

- [Serializing context to the wire](https://github.com/opentracing/opentracing-php#serializing-to-the-wire)
- [Deserializing context from the wire](https://github.com/opentracing/opentracing-php#deserializing-from-the-wire)
- [Propagation formats](https://github.com/opentracing/opentracing-php#propagation-formats)

## Contributing

Before contributing to this open source project, read our [CONTRIBUTING.md](https://github.com/DataDog/dd-trace-php/blob/master/CONTRIBUTING.md).

### Run tests

The recommended way to run tests is using the preconfigured docker images that we provide for the different PHP versions.

  - PHP 5.6: `datadog/docker-library:ddtrace_php_5_6`
  - PHP 7.0: `datadog/docker-library:ddtrace_php_7_0`
  - PHP 7.1: `datadog/docker-library:ddtrace_php_7_1`
  - PHP 7.2: `datadog/docker-library:ddtrace_php_7_2`

In order to run tests open a `bash` in the proper image, e.g. for PHP 5.6;

    $ docker-compose run 5.6 bash

At the begin of you session, or at any time when you update the php extension, install it:

    $ composer install-ext

In order to run the tracer tests:

    $ composer test


Please note that the later is a wrapper around `phpunit`, so you can use all the common
[options](https://phpunit.de/manual/5.7/en/textui.html#textui.clioptions) that you would with `phpunit`. Note, though,
that you need prepend the options list with the additional `--` dashes that `composer` requires:

    # Run a suite and a filter
    $ composer test -- --testsuite=unit --filter=Predis

In order to run tests for the php extension:

    $ composer test-ext

### Fix lint

```bash
composer fix-lint
```

## Releasing

See [RELEASING](RELEASING.md) for more information on releasing new versions.
