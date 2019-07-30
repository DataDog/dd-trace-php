--TEST--
Methods can be traced with internal spans
--ENV--
DD_SERVICE_NAME=default_service
--FILE--
<?php

use DDTrace\SpanData;

class Test
{
    public function testFoo()
    {
        echo "Test::testFoo()...\n";
    }
}

class TestService
{
    public function testServiceFoo()
    {
        echo "TestService::testServiceFoo()...\n";
    }
}

class Foo
{
    public function bar($thoughts, array $bar = [])
    {
        echo "Foo::bar() called...\n";
        return [
            'thoughts' => $thoughts,
            'first' => isset($bar[0]) ? $bar[0] : '(none)',
            'rand' => mt_rand()
        ];
    }
}

dd_trace_method('Test', 'testFoo');
dd_trace_method('TestService', 'testServiceFoo', null, 'test_service');

dd_trace_method(
    'Foo', 'bar',
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
            'foo' => isset($args[1][0]) ? $args[1][0] : '',
            'bar' => isset($args[1][1]) ? $args[1][1] : '',
        ];
    },
    'foo_service'
);

$test = new Test();
$test->testFoo();

$testService = new TestService();
$testService->testServiceFoo();

$foo = new Foo();
$ret = $foo->bar('tracing is awesome', ['zero', 'one', 'two']);
var_dump($ret);

var_dump(dd_trace_reset_span_stack());
var_dump(dd_trace_reset_span_stack());
?>
--EXPECTF--

