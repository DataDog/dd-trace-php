# DD Trace PHP

[![CircleCI](https://circleci.com/gh/DataDog/dd-trace-php/tree/master.svg?style=svg)](https://circleci.com/gh/DataDog/dd-trace-php/tree/master)
[![OpenTracing Badge](https://img.shields.io/badge/OpenTracing-enabled-blue.svg)](http://opentracing.io)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%205.6-8892BF.svg)](https://php.net/)

DataDog APM client that implements an [OpenTracing](http://opentracing.io) Tracer.

## Installation

```
composer require datadog/dd-trace
```

## Requirements

- PHP 5.6 or later

## Usage

In order to be familiar with tracing elements it is recommended to read the [OpenTracing specification](https://github.com/opentracing/specification/blob/master/specification.md).

### Using the tracer

To start using the DataDog Tracer with the OpenTracing API, you should first initialize the tracer:

```php
use DDTrace\Tracer;

...

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
$transport = new Http(
    new Json(),
    $logger,
    [
        'endpoint_url' => 'http://localhost:8126/v0.3/traces', // Agent endpoint
    ]
);
```

Tracer can be customized by the config settings:

```php
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

### Run tests

```bash
composer test
```

### Fix lint

```bash
composer fix-lint
```

## Releasing

See [RELEASING](RELEASING.md) for more information on releasing new versions.
