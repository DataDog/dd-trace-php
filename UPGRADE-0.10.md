# Upgrade to 0.10

<aside class="warning">
This is a breaking change. Read carefully through the docs for the easy step to migrsate.
</aside>

## Composer users

Unless you are doing manual tracing remove the `datadog/dd-trace` dependency from your `composer.json`. If you are doing
manual instrumentation using the OpenTracing API you should still remove the `datadog/dd-trace` dependency and only
reference to `opentracing/opentracing`. If you like to manual instrumentation using Datadog api, then you can keep the
dependency in the `composer.json` file.

## OpenTracing support

While OpenTracing is still supported by ddtrace, it is no longer a required dependency starting with version `0.10.0`.

If you are currently using `opentracing/opentracing` in your own code, make sure to declare the dependency in your `composer.json` file as ddtrace will not provide it anymore.

The ddtrace package is still compatible with OpenTracing, so if you were referencing, `OpenTracing\GlobalTracer::get()` for example, ddtrace will return an OpenTracing-compatible tracer instance.

```php
# This will still work as expected in version 0.10
$tracer = \OpenTracing\GlobalTracer::get();

$span = $tracer->startSpan(/* ... */);
$span->finish();
```

## Laravel Users
In addition to removing `datadog/dd-trace` dependency from composer file, remove the
`DDTrace\Integrations\Laravel\Vx\LaravelProvider` from your declared providers. It is not longer required and we are
moving away from providers based instrumentation.

## Symfony Users
In addition to removing `datadog/dd-trace` dependency from composer file, remove the
`DDTrace\Integrations\Symfony\Vx\SymfonyBundle` from  your declared bundles. It is not longer required and we are
moving away from bundle based instrumentation.

## Disabling auto-instrumentation

If auto-instrumentation does not work well for you:
1. Disable auto-instrumentation setting the following ini value to an empty string: `ddtrace.request_init_hook=''`
1. Immediately after you register the composer autoloader: `DDTrace\Bootstrap::tracerAndIntegrations()`

## Setting the singleton

If you are using a framework integration like Laravel or Symfony, the changes in `0.10.0` should not affect you unless you have done some manual instrumentation.

For those who have manually instrumented ddtrace, the main change that will affect most people is getting and setting the tracer singleton. This is now done with `DDTrace\GlobalTracer` instead of `OpenTracing\GlobalTracer`.

```php
# ddtrace 0.9 and below
use OpenTracing\GlobalTracer;
GlobalTracer::set($tracer);

# ddtrace 0.10 and above
use DDTrace\GlobalTracer;
GlobalTracer::set($tracer);
```

## API changes

All of the OpenTracing interfaces and classes were moved under the `DDTrace` namespace. There were also a few other API changes.

| ddtrace 0.9 and below          | ddtrace 0.10
| ------------------------------ | ------------------------------
| `OpenTracing\GlobalTracer`     | `DDTrace\GlobalTracer`
| `OpenTracing\Formats`          | `DDTrace\Format`
| `OpenTracing\Exceptions\*`     | `DDTrace\Exceptions\*`
| `OpenTracing\Noop*`            | `DDTrace\Noop*`
| `OpenTracing\Reference`        | `DDTrace\Reference`
| `OpenTracing\StartSpanOptions` | `DDTrace\StartSpanOptions`
| `OpenTracing\Scope`            | `DDTrace\Contracts\Scope`
| `OpenTracing\ScopeManager`     | `DDTrace\Contracts\ScopeManager`
| `OpenTracing\Span`             | `DDTrace\Contracts\Span`
| `DDTrace\SpanInterface`        | `DDTrace\Contracts\Span`
| `OpenTracing\SpanContext`      | `DDTrace\Contracts\SpanContext`
| `OpenTracing\Tracer`           | `DDTrace\Contracts\Tracer`

## Removing the OpenTracing dependency

Once all references to the OpenTracing API have been updated, you can remove `opentracing/opentracing` with Composer. 

```bash
$ composer remove opentracing/opentracing
```
