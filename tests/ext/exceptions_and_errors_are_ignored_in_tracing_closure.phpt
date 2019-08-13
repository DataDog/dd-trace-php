--TEST--
Exceptions and errors are ignored when inside a tracing closure
--FILE--
<?php
use DDTrace\SpanData;

class Test
{
    public function testFoo()
    {
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

var_dump(dd_trace_function('mt_rand', function (SpanData $span) {
    $span->name = 'MT';
    throw new Execption('This should be ignored');
}));

$test = new Test();
$test->testFoo();

echo "---\n";

var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECTF--
bool(true)
bool(true)
Test::testFoo() fav num: %d
---
array(2) {
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
    string(2) "MT"
  }
}
