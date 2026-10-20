# DD Trace PHP

[![CircleCI](https://circleci.com/gh/DataDog/dd-trace-php/tree/master.svg?style=svg)](https://circleci.com/gh/DataDog/dd-trace-php/tree/master)
[![OpenTracing Badge](https://img.shields.io/badge/OpenTracing-enabled-blue.svg)](http://opentracing.io)
[![OpenTelemetry Badge](https://img.shields.io/badge/OpenTelemetry-enabled-blue.svg)](https://opentelemetry.io)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%207.0-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-BSD%203--Clause-blue.svg)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/datadog/dd-trace.svg)](https://packagist.org/packages/datadog/dd-trace)
[![Total Downloads](https://img.shields.io/packagist/dt/datadog/dd-trace.svg)](https://packagist.org/packages/datadog/dd-trace)

## Getting Started

<img align="right" style="margin-left:10px" src="bits-php.svg" alt="bits php" width="200px"/>

The Datadog PHP Tracer (**ddtrace**) brings [APM and distributed tracing](https://docs.datadoghq.com/tracing/) to PHP.

### Installing the extension

Datadog’s PHP Tracing Library supports many of the most common PHP versions, PHP web frameworks, datastores, libraries, and more. Prior to installation, please check our latest [compatibility requirements](https://docs.datadoghq.com/tracing/setup_overview/compatibility_requirements/php/).

Visit the [PHP tracer documentation](https://docs.datadoghq.com/tracing/languages/php/) for complete installation instructions.

#### Installation from PECL (datadog_trace) or from source

Compilation of the tracer and the profiler requires cargo to be installed. Ensure that cargo is minimum version 1.91.1, otherwise follow the [official instructions for installing cargo](https://doc.rust-lang.org/cargo/getting-started/installation.html).

### Advanced configuration

For more information about configuring and instrumenting **ddtrace**, view the [configuration documentation](https://docs.datadoghq.com/tracing/setup/php/#configuration).

### OpenTracing

The **ddtrace** package provides an [OpenTracing-compatible tracer](https://docs.datadoghq.com/tracing/custom_instrumentation/php/?tab=tracingfunctioncalls#opentracing).

### Per-subject experimentation opt-out

Enable immediate exclusion through SDK configuration:

```sh
export DD_FEATURE_FLAGS_EXPERIMENTATION_OPT_OUT_MODE=immediate
```

Pass the customer's stored opt-out time in each subject's evaluation context:

```php
use DDTrace\OpenFeature\DataDogProvider;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\OpenFeatureAPI;

$api = OpenFeatureAPI::getInstance();
$api->setProvider(new DataDogProvider());
$attributes = [];
if ($athlete->experimentationOptOutAt !== null) {
    $attributes['dd_experimentation_opt_out_at'] =
        $athlete->experimentationOptOutAt->format(DATE_RFC3339);
}
$enabled = $api->getClient()->getBooleanValue(
    'new-route-planner',
    false,
    new EvaluationContext($athlete->id, new Attributes($attributes))
);
```

The default mode is `disabled`. An explicit constructor setting overrides the environment:
`new DataDogProvider(null, ExperimentationOptOutMode::DISABLED)`, using
`DDTrace\FeatureFlags\ExperimentationOptOutMode`.
The native `DDTrace\FeatureFlags\Client` accepts the same second constructor argument.
Configuration is resolved once when the client or provider is constructed.

In immediate mode, a valid RFC3339 timestamp skips allocations that record experiment exposures.
Evaluation continues through ordinary feature allocations. If none match, the SDK returns the application default.
A future timestamp also takes effect immediately. It does not schedule exclusion.
Omit the attribute to retain normal participation. Null, non-string, empty, and malformed values return
the application default with `INVALID_CONTEXT`.
Unsupported configuration values fail construction. Evaluation telemetry remains independent.

## Contributing

Before contributing to this open source project, read our [CONTRIBUTING.md](CONTRIBUTING.md).

## Security Vulnerabilities

If you have found a security issue, please contact the security team directly at [security@datadoghq.com](mailto:security@datadoghq.com).
