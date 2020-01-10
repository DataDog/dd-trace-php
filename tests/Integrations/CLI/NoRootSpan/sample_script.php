<?php

use DDTrace\SpanData;

\dd_trace_function('dd_test_internal_dummy_function', function (SpanData $span) {
    $span->service = 'some-service';
    $span->name = 'some-name';
    $span->esource = 'some-resource';
    $span->type = 'custom';
});

function dd_test_dummy_function()
{
    dd_test_internal_dummy_function();
}

function dd_test_internal_dummy_function()
{
    error_log('This is dd_test_internal_dummy_method() function');
}

dd_test_dummy_function();

dd_test_dummy_function();
