--TEST--
Exceptions and errors are ignored when inside a tracing closure
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--ENV--
DD_TRACE_DEBUG=1
--INI--
error_reporting=E_ALL
ddtrace.traced_internal_functions=mt_rand,mt_srand
--FILE--
<?php
use DDTrace\SpanData;

class Test
{
    public function testFoo()
    {
        mt_srand(42);
        printf(
            "Test::testFoo() fav num: %d\n",
            mt_rand()
        );
    }
}

dd_trace_method('Test', 'testFoo', function (SpanData $span) {
    $span->name = 'TestFoo';
    $span->service = $this_normally_raises_a_notice; // E_NOTICE
});

dd_trace_function('mt_srand', function (SpanData $span) {
    $span->name = 'MTSeed';
    throw new Exception('This should be ignored');
});

dd_trace_function('mt_rand', function (SpanData $span) {
    $span->name = 'MTRand';
    htmlentities('<b>', 0, 'BIG5'); // E_STRICT
});

$test = new Test();
$test->testFoo();

array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, dd_trace_serialize_closed_spans());
var_dump(error_get_last());
?>
--EXPECTF--
Exception thrown in tracing closure for mt_srand: This should be ignored
Error raised in tracing closure for mt_rand(): htmlentities(): Only basic entities substitution is supported for multi-byte encodings other than UTF-8; functionality is equivalent to htmlspecialchars in %s on line %d
Test::testFoo() fav num: %d
Error raised in tracing closure for testfoo(): Undefined variable: this_normally_raises_a_notice in %s on line %d
TestFoo
MTRand
MTSeed
NULL
