<?php

use DDTrace\GlobalTracer;
use DDTrace\SpanData;
use DDTrace\Type;

\DDTrace\trace_function('internal_span', function (SpanData $span) {
    $span->service = 'some_service';
    $span->type = Type::CLI;

    $span->meta['extracted_trace_id'] = \DDTrace\trace_id();
    $span->meta['extracted_span_id'] = \DDTrace\active_span()->id;
});

function internal_span()
{
}


// Root span
$tracer = GlobalTracer::get();
$root = $tracer->getActiveSpan();
$root->setTag('extracted_trace_id', \DDTrace\trace_id());
$root->setTag('extracted_span_id', \DDTrace\active_span()->id);

internal_span();
