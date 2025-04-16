<?php

use DDTrace\GlobalTracer;

function do_manual_instrumentation_subspan()
{
    $tracer = GlobalTracer::get();
    $subScope = $tracer->startActiveSpan('sub_operation');
    $subScope->close();
}

DDTrace\trace_function('do_manual_instrumentation_subspan', function () {
});

function do_manual_instrumentation_root_before()
{
    $tracer = GlobalTracer::get();
    $rootScope = $tracer->startRootSpan("custom_root_operation");

    do_manual_instrumentation_subspan();

    $rootScope->close();
}

do_manual_instrumentation_root_before();

error_log('Custom root is done');

function do_manual_instrumentation_within_root_trace_function()
{
    $tracer = GlobalTracer::get();
    $subScope = $tracer->startActiveSpan('second_sub_operation');
    $subScope->getSpan()->setTag('result', array_sum([1, 41]));
    $subScope->close();
}

DDTrace\trace_function('array_sum', function () {
});

$i = 0;
DDTrace\trace_function('do_manual_instrumentation_within_root_trace_function', function ($span) use (&$i) {
    $tracer = GlobalTracer::get();
    $subScope = $tracer->startActiveSpan('first_sub_operation');
    $subScope->getSpan()->setTag('result', array_sum([1, 41]));
    $subScope->close();
    $span->resource = "run " . ++$i;
});

do_manual_instrumentation_within_root_trace_function();

do_manual_instrumentation_within_root_trace_function();

error_log('Implicit root is done');
