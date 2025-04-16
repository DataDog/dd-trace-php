--TEST--
Exceptions and errors are ignored when inside a tracing closure
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=mt_rand,mt_srand
DD_APPSEC_ENABLED=0
--INI--
error_reporting=E_ALL
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

DDTrace\trace_method('Test', 'testFoo', function (SpanData $span) {
    $span->name = 'TestFoo';
    $span->service = $this_normally_raises_an_error;
});

DDTrace\trace_function('mt_srand', function (SpanData $span) {
    $span->name = 'MTSeed';
    throw new Exception('This should be ignored');
});

DDTrace\trace_function('mt_rand', function (SpanData $span) {
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
[ddtrace] [warning] Exception thrown in ddtrace's closure defined at %s:21 for mt_srand(): This should be ignored in %s on line %d
[ddtrace] [warning] Error raised in ddtrace's closure defined at %s:26 for mt_rand(): htmlentities(): Only basic entities substitution is supported for multi-byte encodings other than UTF-8; functionality is equivalent to htmlspecialchars in %s on line %d
Test::testFoo() fav num: %d
[ddtrace] [warning] %s in ddtrace's closure defined at %s:16 for Test::testFoo(): Undefined variable%sthis_normally_raises_an_%s
TestFoo
MTRand
MTSeed
NULL
[ddtrace] [info] No finished traces to be sent to the agent
