<?php

namespace DDTrace;

use DDTrace\Formats;
use OpenTracing\Reference;
use OpenTracing\StartSpanOptions;
use OpenTracing\Tracer as OTTracer;

/**
 * A factory to create instances of StartSpanOptions.
 */
class StartSpanOptionsFactory
{
    /**
     * Creates an instance of StartSpanOptions making sure that if DD specific distributed tracing headers exist,
     * then the \OpenTracing\Span that is about to be started will get the proper reference to the remote Span.
     *
     * @param OTTracer $tracer
     * @param array $options
     * @param array $headers An associative array containing header names and values.
     * @return StartSpanOptions
     */
    public static function createForWebRequest(OTTracer $tracer, array $options = [], array $headers = [])
    {
        $globalConfiguration = Configuration::get();

        if ($globalConfiguration->isDistributedTracingEnabled()
                && $spanContext = $tracer->extract(Formats\HTTP_HEADERS, $headers)) {
            $options[Reference::CHILD_OF] = $spanContext;
        }

        return StartSpanOptions::create($options);
    }
}
