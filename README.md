# DD Trace PHP

[![CircleCI](https://circleci.com/gh/DataDog/dd-trace-php/tree/master.svg?style=svg)](https://circleci.com/gh/DataDog/dd-trace-php/tree/master)
[![CodeCov](https://codecov.io/gh/DataDog/dd-trace-php/branch/master/graph/badge.svg?token=eXio8H7vwF)](https://codecov.io/gh/DataDog/dd-trace-php)
[![OpenTracing Badge](https://img.shields.io/badge/OpenTracing-enabled-blue.svg)](http://opentracing.io)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%205.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-BSD%203--Clause-blue.svg)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/datadog/dd-trace.svg)](https://packagist.org/packages/datadog/dd-trace)
[![Total Downloads](https://img.shields.io/packagist/dt/datadog/dd-trace.svg)](https://packagist.org/packages/datadog/dd-trace)

## Getting Started

<img align="right" style="margin-left:10px" src="https://user-images.githubusercontent.com/22597395/203064226-b8e84320-87b3-4c38-8305-9933c4ab4996.svg" alt="bits php" width="200px"/>

The Datadog PHP Tracer (**ddtrace**) brings [APM and distributed tracing](https://docs.datadoghq.com/tracing/) to PHP.

### Installing the extension

Datadog’s PHP Tracing Library supports many of the most common PHP versions, PHP web frameworks, datastores, libraries, and more. Prior to installation, please check our latest [compatibility requirements](https://docs.datadoghq.com/tracing/setup_overview/compatibility_requirements/php/).

Visit the [PHP tracer documentation](https://docs.datadoghq.com/tracing/languages/php/) for complete installation instructions.

#### Installation from PECL (datadog_trace) or from source

Compilation of the tracer and the profiler requires cargo to be installed. Ensure that cargo is minimum version 1.64.0, otherwise follow the [official instructions for installing cargo](https://doc.rust-lang.org/cargo/getting-started/installation.html).

### Advanced configuration

For more information about configuring and instrumenting **ddtrace**, view the [configuration documentation](https://docs.datadoghq.com/tracing/setup/php/#configuration).

### OpenTracing

The **ddtrace** package provides an [OpenTracing-compatible tracer](https://docs.datadoghq.com/tracing/custom_instrumentation/php/?tab=tracingfunctioncalls#opentracing).

## Contributing

Before contributing to this open source project, read our [CONTRIBUTING.md](CONTRIBUTING.md).

## Security Vulnerabilities

If you have found a security issue, please contact the security team directly at [security@datadoghq.com](mailto:security@datadoghq.com).
