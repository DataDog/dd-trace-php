# Upgrade to 0.9

Before `0.9.0`, ddtrace required the `opentracing/opentracing` dependency but this requirement as been removed. In order to remove the dependency completely, you'll need to update all references to the OpenTracing API's.

## Setting the singleton

The main change that will affect most people is getting and setting the tracer singleton. This is now done with `DDTrace\GlobalTracer` instead of `OpenTracing\GlobalTracer`.

```php
# ddtrace 0.8 and below
use OpenTracing\GlobalTracer;
GlobalTracer::set($tracer);

# ddtrace 0.9 and above
use DDTrace\GlobalTracer;
GlobalTracer::set($tracer);
```

## API changes

All of the OpenTracing interfaces and classes were moved under the `DDTrace` namespace.

| ddtrace 0.8 and below          | ddtrace 0.9
| ------------------------------ | ------------------------------
| `OpenTracing\GlobalTracer`     | `DDTrace\GlobalTracer`
| `OpenTracing\Formats`          | `DDTrace\Formats`
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
