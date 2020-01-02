--TEST--
Exceptions and errors are ignored when inside a tracing closure
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
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
    $span->service = $this_normally_raises_a_notice;
});

dd_trace_function('mt_srand', function (SpanData $span) {
    $span->name = 'MTSeed';
    throw new Exception('This should be ignored');
});

dd_trace_function('mt_rand', function (SpanData $span) {
    $span->name = 'MTRand';
    // TODO: Ignore fatals like this on PHP 5
    if (PHP_VERSION_ID >= 70000) {
        this_function_does_not_exist();
        //$foo = new ThisClassDoesNotExist();
    }
});

$test = new Test();
$test->testFoo();

array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, dd_trace_serialize_closed_spans());
var_dump(error_get_last());
?>
--EXPECTF--
Test::testFoo() fav num: %d
TestFoo
MTRand
MTSeed
NULL
