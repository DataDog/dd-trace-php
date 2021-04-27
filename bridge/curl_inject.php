<?php

namespace DDTrace\Bridge {

    use DDTrace\Format;
    use DDTrace\GlobalTracer;
    use DDTrace\SpanContext;

    function inject_distributed_tracing_headers($format, array &$headers)
    {
        if (
            !\class_exists('DDTrace\\SpanContext', false)
            || !\class_exists('DDTrace\\GlobalTracer', false)
        ) {
            return;
        }

        $span = GlobalTracer::get()->getActiveSpan();
        if (null === $span) {
            return;
        }
        $context = $span->getContext();

        /* We can't use the existing context because only userland spans are
         * represented. As a result, we create a new context with
         * dd_trace_peek_span_id() to get the active span ID.
         */
        $activeSpanId = \dd_trace_peek_span_id();
        $newContext = new SpanContext($context->getTraceId(), $activeSpanId);

        if (\property_exists($context, 'origin')) {
            $newContext->origin = $context->origin;
        }

        GlobalTracer::get()->inject($newContext, $format, $headers);
    }

    // This gets called before curl_exec() calls from the C extension on PHP 5
    function curl_inject_distributed_headers($ch, array $headers)
    {
        if (!\class_exists('DDTrace\\Format', false)) {
            return;
        }

        inject_distributed_tracing_headers(Format::CURL_HTTP_HEADERS, $headers);
        \curl_setopt($ch, \CURLOPT_HTTPHEADER, $headers);
    }

}
