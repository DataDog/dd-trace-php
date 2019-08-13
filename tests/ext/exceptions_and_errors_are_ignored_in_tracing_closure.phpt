--TEST--
Exceptions and errors are ignored when inside a tracing closure
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

var_dump(dd_trace_method('Test', 'testFoo', function (SpanData $span) {
    $span->name = 'TestFoo';
    $span->service = $this_normally_raises_a_notice;
}));

var_dump(dd_trace_function('mt_srand', function (SpanData $span) {
    $span->name = 'MTSeed';
    throw new Exception('This should be ignored');
}));

var_dump(dd_trace_function('mt_rand', function (SpanData $span) {
    $span->name = 'MTRand';
    // TODO: Ignore fatals like this on PHP 5
    if (PHP_VERSION_ID >= 70000) {
        this_function_does_not_exist();
        //$foo = new ThisClassDoesNotExist();
    }
}));

$test = new Test();
$test->testFoo();

echo "---\n";

var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
Test::testFoo() fav num: %d
---
array(3) {
  [0]=>
  array(5) {
    ["trace_id"]=>
    int(%d)
    ["span_id"]=>
    int(%d)
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(7) "TestFoo"
  }
  [1]=>
  array(6) {
    ["trace_id"]=>
    int(%d)
    ["span_id"]=>
    int(%d)
    ["parent_id"]=>
    int(%d)
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(6) "MTRand"
  }
  [2]=>
  array(6) {
    ["trace_id"]=>
    int(%d)
    ["span_id"]=>
    int(%d)
    ["parent_id"]=>
    int(%d)
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(6) "MTSeed"
  }
}
