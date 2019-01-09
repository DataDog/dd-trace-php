# Datadog PHP OpenTracing Support

Whereas [OpenTracing](https://opentracing.io/) is not required to run ddtrace, an OpenTracing-compatible tracer is provided. The following documentation is specific to the OpenTracing package, but many of the concepts work the same for the ddtrace extension.

If you already have OpenTracing-compatible code, you can create an instance of the ddtrace OpenTracing tracer and set it as the OpenTracing tracer singleton.

```php
use DDTrace\OpenTracer\Tracer;
use OpenTracing\GlobalTracer;

GlobalTracer::set(new Tracer());
```

Once the ddtrace OpenTracing tracer has been set as the singleton, you can use the [OpenTracing PHP API](https://github.com/opentracing/opentracing-php) as expected.

```php
use DDTrace\OpenTracer\Tracer;
use OpenTracing\GlobalTracer;

$tracer = new Tracer();
GlobalTracer::set($tracer);
register_shutdown_function(function() {
    GlobalTracer::get()->flush();
});

// Start a root span
$span = $tracer->startSpan('my_base_trace');

// Run your application here
// $myApplication->run();

// Close the root span after the application code has finished
$span->finish();
```

#### Creating Spans

- [Starting a root span](https://github.com/opentracing/opentracing-php#starting-an-empty-trace-by-creating-a-root-span)
- [Starting a span for a given request](https://github.com/opentracing/opentracing-php#creating-a-span-given-an-existing-request)
- [Active span and scope manager](https://github.com/opentracing/opentracing-php#active-spans-and-scope-manager)
  - [Creating a child span assigning parent manually](https://github.com/opentracing/opentracing-php#creating-a-child-span-assigning-parent-manually)
  - [Creating a child span using automatic active span management](https://github.com/opentracing/opentracing-php#creating-a-child-span-using-automatic-active-span-management)
- [Using span options](https://github.com/opentracing/opentracing-php#using-span-options)

#### Propagation of context

- [Serializing context to the wire](https://github.com/opentracing/opentracing-php#serializing-to-the-wire)
- [Deserializing context from the wire](https://github.com/opentracing/opentracing-php#deserializing-from-the-wire)
- [Propagation formats](https://github.com/opentracing/opentracing-php#propagation-formats)
