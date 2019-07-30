--TEST--
Functions can be traced with internal spans
--ENV--
DD_SERVICE_NAME=default_service
--FILE--
<?php

use DDTrace\SpanData;

function testFoo()
{
    echo "testFoo()...\n";
}

function testServiceFoo()
{
    echo "testServiceFoo()...\n";
}

function bar($thoughts, array $bar = [])
{
    echo "bar() called...\n";
    return [
        'thoughts' => $thoughts,
        'first' => isset($bar[0]) ? $bar[0] : '(none)',
        'rand' => array_sum([mt_rand(0, 100), mt_rand(0, 100)])
    ];
}

dd_trace_function('array_sum', null, 'php');
dd_trace_function('mt_rand', null, 'php');
dd_trace_function('testFoo');
dd_trace_function('testServiceFoo', null, 'test_service');

dd_trace_function(
    'bar',
    function (SpanData $span, $args, $retval) {
        $span->name = 'FooName';
        $span->resource = 'FooResource';
        $span->service = 'FooService';
        $span->type = 'FooType';
        $span->meta = [
            'args.0' => isset($args[0]) ? $args[0] : '',
            'retval.thoughts' => isset($retval['thoughts']) ? $retval['thoughts'] : '',
            'retval.first' => isset($retval['first']) ? $retval['first'] : '',
            'retval.rand' => isset($retval['rand']) ? $retval['rand'] : '',
        ];
        $span->metrics = [
            'foo' => isset($args[1]) ? $args[1] : '',
            'bar' => isset($args[2]) ? $args[2] : '',
        ];
    },
    'foo_service'
);

testFoo();
testServiceFoo();
$ret = bar('tracing is awesome', ['zero', 'one', 'two']);
var_dump($ret);

var_dump(dd_trace_reset_span_stack());
var_dump(dd_trace_reset_span_stack());
?>
--EXPECTF--

