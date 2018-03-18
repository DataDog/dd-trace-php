# DD Trace PHP

[![CircleCI](https://circleci.com/gh/DataDog/dd-trace-php/tree/master.svg?style=svg)](https://circleci.com/gh/DataDog/dd-trace-php/tree/master)
[![OpenTracing Badge](https://img.shields.io/badge/OpenTracing-enabled-blue.svg)](http://opentracing.io)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%205.6-8892BF.svg)](https://php.net/)

Datadog APM client that implements an [OpenTracing](http://opentracing.io) Tracer.

## Installation

```
composer require datadog/dd-trace
```

## Requirements

- PHP 5.6 or later

## Getting started

To start using the Datadog Tracer with the OpenTracing API, you should first initialize the tracer:

```php
use DDTrace\Encoders\Json;
use DDTrace\Propagators\TextMap;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use OpenTracing\Formats;

...

// Transport layer that communicates with the agent
$transport = new Http(new Json(), $logger, [
   'endpoint_url' => 'http://localhost:8126/v0.3/traces',
]);

// Propagation for inject/extract contexts to/from the wire
$textMap = new TextMap();

// Config for tracer
$config = [
    'service_name' => 'my_service',
    'enabled' => true,
    'global_tags' => ['host' => 'hostname'],
];

$tracer = new Tracer($transport, [
    Formats\TEXT_MAP => $textMap,
    $config,
]);

// Sets a global tracer (singleton). Ideally tracer should be
// injected as a dependency
GlobalTracer::set($tracer);
```

## Usage

See [Opentracing documentation](https://github.com/opentracing/opentracing-php) for some usage patterns.

## Contributing

### Run tests

```bash
composer test
```

### Fix lint

```bash
composer fix-lint
```
