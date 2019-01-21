<?php

namespace DDTrace;

use DDTrace\Contracts\Tracer as TracerInterface;

/**
 * A factory to create instances of StartSpanOptions.
 */
class StartSpanOptionsFactory
{
    /**
     * Creates an instance of StartSpanOptions making sure that if DD specific distributed tracing headers exist,
     * then the \DDTrace\Contracts\Span that is about to be started will get the proper reference to the remote Span.
     *
     * @param TracerInterface $tracer
     * @param array $options
     * @param array $headers An associative array containing header names and values.
     * @return StartSpanOptions
     */
    public static function createForWebRequest(TracerInterface $tracer, array $options = [], array $headers = [])
    {
        $globalConfiguration = Configuration::get();

        if ($globalConfiguration->isDistributedTracingEnabled()
                && $spanContext = $tracer->extract(Format::HTTP_HEADERS, $headers)) {
            $options[Reference::CHILD_OF] = $spanContext;
        }

        return StartSpanOptions::create($options);
    }
}
