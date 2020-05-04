<?php

namespace DDTrace;

function init()
{
    dd_trace_function('array_sum', function (\DDTrace\SpanData $span) {
        $span->name = 'array_sum';
    });
    echo 'Request init hook loaded.' . PHP_EOL;
}

init();
