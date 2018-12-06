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

The PHP tracer is composed of a PHP extension and a Composer package. You'll need to install both in order to start tracing your PHP projects. First we'll install the Composer package.

```bash
$ composer require datadog/dd-trace opentracing/opentracing:@dev
```

> **Note:** Since the [OpenTracing dependency](https://github.com/opentracing/opentracing-php) is still in beta, adding the `opentracing/opentracing:@dev` argument to the `composer require` command will ensure the library is installed without changing your Composer minimum stability settings.

Next we'll install the `ddtrace` extension. The command we use to install it varies depending on your platform.

```bash
# using RPM package (RHEL/Centos 6+, Fedora 20+)
$ rpm -ivh datadog-php-tracer.rpm

# using DEB package (Debian Jessie+ , Ubuntu 14.04+)
$ deb -i datadog-php-tracer.deb

# using APK package (Alpine)
$ apk add datadog-php-tracer.apk --allow-untrusted

# using tar.gz archive (Other distributions using libc6)
$ tar -xf datadog-php-tracer.tar.gz -C /
  /opt/datadog-php/bin/post-install.sh
```

### Usage

Once the `ddtrace` extension and Composer package is installed, you can start tracing your PHP project by wrapping your application code with a [root span](https://docs.datadoghq.com/tracing/visualization/#spans) from the [tracer](https://docs.datadoghq.com/tracing/visualization/#trace).

```php
use DDTrace\Tracer;
use OpenTracing\GlobalTracer;
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
$scope = $tracer->startSpan('my_base_trace');

// Run your application here
// $myApplication->run();

// Close the root span after the application code has finished
$scope->close();
```

Notice we didn't specify an [API key](https://app.datadoghq.com/account/settings#api) or any web endpoints. That's because the API key is set at the [agent layer](https://docs.datadoghq.com/agent/?tab=agentv6), so the PHP code just needs to know the hostname and port of the agent to send traces to Datadog. By default the PHP tracer will assume the agent hostname is `localhost` and the port is `8126`. If you need to change these values, check out the [configuration documentation](docs/getting_started.md#configuration).

### Viewing the trace

Assuming the agent is running with APM enabled and it is configured with our API key, and assuming we successfully installed the `ddtrace` extension and the `datadog/dd-trace` package with Composer, we should be able to head over to [the APM UI](https://app.datadoghq.com/apm/services) to see our trace.

> **Note:** It might take a few minutes before your trace appears in the UI. Just refresh the page a few times until you see the screen change.

### Digging deeper

For more information about configuration and specific framework integrations, check out the [getting started docs](docs/getting_started.md).

### Advanced configuration

In order to be familiar with tracing elements it is recommended to read the [OpenTracing specification](https://github.com/opentracing/specification/blob/master/specification.md).

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

Before contributing to this open source project, read our [CONTRIBUTING.md](CONTRIBUTING.md).

## Releasing

See [RELEASING](RELEASING.md) for more information on releasing new versions.

## Security Vulnerabilities

If you have found a security issue, please contact the security team directly at [security@datadoghq.com](mailto:security@datadoghq.com).
