# Upgrade to 0.9

Before `0.9.0`, ddtrace required the `opentracing/opentracing` dependency. You can continue to use ddtrace with this dependency if you like, but if you'd rather remove the dependency completely, you'll need to update all references to the OpenTracing API's.

## Setting the singleton

The main change that will affect everyone is getting and setting the tracer singleton. This is now done with `DDTrace\GlobalTracer` instead of `OpenTracing\GlobalTracer`.

```php
# ddtrace 0.8 and below
use OpenTracing\GlobalTracer;
GlobalTracer::set($tracer);

# ddtrace 0.9 and above
use DDTrace\GlobalTracer;
GlobalTracer::set($tracer);
```

## API changes

| ddtrace 0.8 and below      | ddtrace 0.9
| -------------------------- | ------------------------------
| `OpenTracing\GlobalTracer` | `DDTrace\GlobalTracer`
| `OpenTracing\Formats`      | `DDTrace\Formats`
| `DDTrace\NoopSpan`         | `DDTrace\OpenTracing\NoopSpan`
| `DDTrace\SpanInterface`    | `DDTrace\Contracts\Span`
| `OpenTracing\Exceptions\*` | `DDTrace\Exceptions\*`

All of the remaining OpenTracing interfaces and classes were moved under the `DDTrace` namespace.

| ddtrace 0.8 and below          | ddtrace 0.9
| ------------------------------ | --------------------------------------
| `OpenTracing\Scope`            | `DDTrace\OpenTracing\Scope`
| `OpenTracing\ScopeManager`     | `DDTrace\OpenTracing\ScopeManager`
| `OpenTracing\Span`             | `DDTrace\OpenTracing\Span`
| `OpenTracing\SpanContext`      | `DDTrace\OpenTracing\SpanContext`
| `OpenTracing\Tracer`           | `DDTrace\OpenTracing\Tracer`
| `OpenTracing\Reference`        | `DDTrace\OpenTracing\Reference`
| `OpenTracing\StartSpanOptions` | `DDTrace\OpenTracing\StartSpanOptions`
| `OpenTracing\NoopScope`        | `DDTrace\OpenTracing\NoopScope`
| `OpenTracing\NoopScopeManager` | `DDTrace\OpenTracing\NoopScopeManager`
| `OpenTracing\NoopSpan`         | `DDTrace\OpenTracing\NoopSpan`
| `OpenTracing\NoopSpanContext`  | `DDTrace\OpenTracing\NoopSpanContext`
| `OpenTracing\NoopTracer`       | `DDTrace\OpenTracing\NoopTracer`

## Removing the OpenTracing dependency

Once all references to the OpenTracing API have been updated, you can remove `opentracing/opentracing` with Composer. 

```bash
$ composer remove opentracing/opentracing
```
