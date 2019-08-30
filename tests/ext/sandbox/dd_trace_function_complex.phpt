--TEST--
dd_trace_function() can trace with internal spans
--FILE--
<?php
use DDTrace\SpanData;

function testFoo()
{
    echo "testFoo()\n";
}

function addOne($num)
{
    echo "addOne()\n";
    return $num + 1;
}

function bar($thoughts, array $bar = [])
{
    echo "bar()\n";
    return [
        'thoughts' => $thoughts,
        'first' => isset($bar[0]) ? $bar[0] : '(none)',
        'rand' => array_sum([
            mt_rand(0, 100),
            addOne(mt_rand(0, 100)),
        ])
    ];
}

var_dump(dd_trace_function('array_sum', function (SpanData $span) {
    $span->name = 'ArraySum';
}));
var_dump(dd_trace_function('mt_rand', null));
var_dump(dd_trace_function('testFoo', function (SpanData $span) {
    $span->name = 'TestFoo';
}));
var_dump(dd_trace_function('addOne', function (SpanData $span) {
    $span->name = 'AddOne';
}));
var_dump(dd_trace_function(
    'bar',
    function (SpanData $span, $args, $retval) {
        $span->name = 'BarName';
        $span->resource = 'BarResource';
        $span->service = 'BarService';
        $span->type = 'BarType';
        $span->meta = [
            'args.0' => isset($args[0]) ? $args[0] : '',
            'retval.thoughts' => isset($retval['thoughts']) ? $retval['thoughts'] : '',
            'retval.first' => isset($retval['first']) ? $retval['first'] : '',
            'retval.rand' => isset($retval['rand']) ? $retval['rand'] : '',
        ];
        $span->metrics = [
            'foo' => isset($args[1][1]) ? $args[1][1] : '',
            'bar' => isset($args[1][2]) ? $args[1][2] : '',
        ];
    }
));

testFoo();
var_dump(addOne(0));
$ret = bar('tracing is awesome', ['first', 'foo-red', 'bar-green']);
var_dump($ret);

echo "---\n";

var_dump(dd_trace_serialize_closed_spans());
var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECTF--
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
testFoo()
addOne()
int(1)
bar()
addOne()
array(3) {
  ["thoughts"]=>
  string(18) "tracing is awesome"
  ["first"]=>
  string(5) "first"
  ["rand"]=>
  int(%d)
}
---
array(5) {
  [0]=>
  array(10) {
    ["trace_id"]=>
    int(%d)
    ["span_id"]=>
    int(%d)
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(7) "BarName"
    ["resource"]=>
    string(11) "BarResource"
    ["service"]=>
    string(10) "BarService"
    ["type"]=>
    string(7) "BarType"
    ["meta"]=>
    array(4) {
      ["args.0"]=>
      string(18) "tracing is awesome"
      ["retval.thoughts"]=>
      string(18) "tracing is awesome"
      ["retval.first"]=>
      string(5) "first"
      ["retval.rand"]=>
      int(%d)
    }
    ["metrics"]=>
    array(2) {
      ["foo"]=>
      string(7) "foo-red"
      ["bar"]=>
      string(9) "bar-green"
    }
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
    string(8) "ArraySum"
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
    string(6) "AddOne"
  }
  [3]=>
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
    string(6) "AddOne"
  }
  [4]=>
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
}
array(0) {
}
