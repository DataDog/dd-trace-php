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

### OpenFeature evaluation telemetry opt-out

The Datadog OpenFeature provider can suppress evaluation telemetry for individual subjects. This option is disabled by default.
Enable the provider option, then set `dd_evaluation_telemetry_opt_out` to boolean `true` in the evaluation context:

```php
use DDTrace\OpenFeature\DataDogProvider;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\OpenFeatureAPI;

$api = OpenFeatureAPI::getInstance();
$api->setProvider(new DataDogProvider(enableEvaluationTelemetryOptOutParameter: true));
$context = new EvaluationContext('subject-123', new Attributes([
    'dd_evaluation_telemetry_opt_out' => true,
]));
$value = $api->getClient()->getBooleanValue('checkout-redesign', false, $context);
```

Only boolean `true` suppresses new evaluation metrics and automatic flag span annotations. Strings such as `"true"` do not suppress telemetry.
The provider reads the final merged context after OpenFeature `before` hooks.
Flag results, experiment exposures, custom hooks, unrelated telemetry, and previously queued data remain unchanged.
This option does not disable experiment participation or exposure logging. The native `DDTrace\FeatureFlags\Client` API remains unchanged.

To enable support through the environment, set this variable before PHP starts:

```sh
export DD_FEATURE_FLAGS_EVALUATION_TELEMETRY_PER_SUBJECT_OPT_OUT_ENABLED=true
```

Then construct `new DataDogProvider()` without the option. The provider captures tracer configuration when it is constructed.
An explicit `true` or `false` constructor option overrides the environment. Omitted or `null` options use tracer configuration.
Unset or invalid environment values default to disabled. The equivalent PHP INI option is `datadog.feature_flags_evaluation_telemetry_per_subject_opt_out_enabled`.
Environment activation only enables support for the subject attribute. Subjects without typed boolean `true` continue to record evaluation telemetry.

## Contributing

Before contributing to this open source project, read our [CONTRIBUTING.md](CONTRIBUTING.md).

## Security Vulnerabilities

If you have found a security issue, please contact the security team directly at [security@datadoghq.com](mailto:security@datadoghq.com).
