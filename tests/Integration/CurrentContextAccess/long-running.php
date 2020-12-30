<?php

use DDTrace\GlobalTracer;
use DDTrace\SpanData;
use DDTrace\Type;

\DDTrace\trace_function('root_span', function (SpanData $span) {
    $span->service = 'some_service';
    $span->type = Type::CLI;

    $span->meta['extracted_trace_id'] = \dd_trace_peek_trace_id();
    $span->meta['extracted_span_id'] = \dd_trace_peek_span_id();
});

\DDTrace\trace_function('internal_span', function (SpanData $span) {
    $span->service = 'some_service';
    $span->type = Type::CLI;

    $span->meta['extracted_trace_id'] = \dd_trace_peek_trace_id();
    $span->meta['extracted_span_id'] = \dd_trace_peek_span_id();
});

function internal_span()
{
}

function root_span()
{
    internal_span();
}

root_span();
