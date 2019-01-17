# DD Trace PHP

[![CircleCI](https://circleci.com/gh/DataDog/dd-trace-php/tree/master.svg?style=svg)](https://circleci.com/gh/DataDog/dd-trace-php/tree/master)
[![OpenTracing Badge](https://img.shields.io/badge/OpenTracing-enabled-blue.svg)](http://opentracing.io)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%205.6-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-BSD%203--Clause-blue.svg)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/datadog/dd-trace.svg)](https://packagist.org/packages/datadog/dd-trace)
[![Total Downloads](https://img.shields.io/packagist/dt/datadog/dd-trace.svg)](https://packagist.org/packages/datadog/dd-trace)

PHP Tracer

> **This is Beta software.** We do not recommend using it in production yet.

## Getting Started

The Datadog PHP Tracer brings [APM and distributed tracing](https://docs.datadoghq.com/tracing/) to PHP.

### Prerequisites

If you haven't already, [sign up for a free Datadog account](https://www.datadoghq.com/) and [download and install the Datadog agent](https://docs.datadoghq.com/tracing/setup/?tab=agent630).

> **Make sure that APM is enabled.** The agent does not have APM enabled by default so make sure [to enable it](https://docs.datadoghq.com/tracing/setup/?tab=agent630#agent-configuration).

### Installation

The PHP tracer is composed of a PHP extension and a Composer package. You'll need to install both in order to start tracing your PHP projects.

#### Composer installation

First we'll install the Composer package.

```bash
$ composer require datadog/dd-trace
```

#### Installing the extension

Next we'll install the `ddtrace` extension. The easiest way to install the extension is from [PECL](https://pecl.php.net/package/datadog_trace).

```bash
$ sudo pecl install datadog_trace-beta
```

After the installation is complete, you'll need to [enable the extension](docs/getting_started.md#enabling-the-extension).

If you don't have `pecl` installed, you can [install the extension manually](docs/getting_started.md#installing-the-extension-manually).

### Instrumentation

Once the `ddtrace` extension and Composer package is installed, you can start tracing your PHP project. There are a few framework instrumentations available out of the box.

* [Laravel 4 & 5 instrumentation](docs/getting_started.md#laravel-integration)
* [Lumen 5 instrumentation](docs/getting_started.md#lumen-integration)
* [Symfony 3 & 4 instrumentation](docs/getting_started.md#symfony-integration)
* [Zend Framework 1 instrumentation](docs/getting_started.md#zend-framework-1-integration)

### Manual instrumentation

If you are using another framework or CMS that is not listed above, you can manually instrument the tracer by wrapping your application code with a [root span](https://docs.datadoghq.com/tracing/visualization/#spans) from the [tracer](https://docs.datadoghq.com/tracing/visualization/#trace).

```php
use DDTrace\Tracer;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\IntegrationsLoader;

// Creates a tracer with default transport and propagators
$tracer = new Tracer();

// Sets a global tracer (singleton)
GlobalTracer::set($tracer);
// Flushes traces to agent on script shutdown
register_shutdown_function(function() {
    GlobalTracer::get()->flush();
});

// Enable the built-in integrations
IntegrationsLoader::load();

// Start a root span
$span = $tracer->startSpan('my_base_trace');

// Run your application here
// $myApplication->run();

// Close the root span after the application code has finished
$span->finish();
```

Notice we didn't specify an [API key](https://app.datadoghq.com/account/settings#api) or any web endpoints. That's because the API key is set at the [agent layer](https://docs.datadoghq.com/agent/?tab=agentv6), so the PHP code just needs to know the hostname and port of the agent to send traces to Datadog. By default the PHP tracer will assume the agent hostname is `localhost` and the port is `8126`. If you need to change these values, check out the [configuration documentation](docs/getting_started.md#configuration).

### Viewing the trace

Assuming the agent is running with APM enabled and it is configured with our API key, and assuming we successfully installed the `ddtrace` extension and the `datadog/dd-trace` package with Composer, we should be able to head over to [the APM UI](https://app.datadoghq.com/apm/services) to see our trace.

> **Note:** It might take a few minutes before your trace appears in the UI. Just refresh the page a few times until you see the screen change.

### Digging deeper

For more information about configuration and specific framework integrations, check out the [getting started docs](docs/getting_started.md).

### Advanced configuration

> **Note:** As ddtrace is modeled off of [OpenTracing](https://opentracing.io/), it is recommended to read the [OpenTracing specification](https://github.com/opentracing/specification/blob/master/specification.md) to familiarize yourself with distributed tracing concepts. The ddtrace package also provides an [OpenTracing-compatible tracer](docs/open_tracing.md).

The transport can be customized by the config parameters:

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

The tracer can be customized by the config settings:

```php
use DDTrace\Tracer;
use DDTrace\Format;

// Config for tracer
$config = [
    'service_name' => 'my_service', // The name of the service.
    'enabled' => true, // If tracer is not enabled, all spans will be created as noop.
    'global_tags' => ['host' => 'hostname'], // Set of tags being added to every span.
];

$tracer = new Tracer(
    $transport,
    [ Format::TEXT_MAP => $textMap ],
    $config
);
```

### OpenTracing

The ddtrace package provides an [OpenTracing-compatible tracer](docs/open_tracing.md).

## Contributing

Before contributing to this open source project, read our [CONTRIBUTING.md](CONTRIBUTING.md).

## Releasing

See [RELEASING](RELEASING.md) for more information on releasing new versions.

## Security Vulnerabilities

If you have found a security issue, please contact the security team directly at [security@datadoghq.com](mailto:security@datadoghq.com).
