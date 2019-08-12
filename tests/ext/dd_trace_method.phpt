--TEST--
dd_trace_method() can trace with internal spans
--FILE--
<?php
use DDTrace\SpanData;

class Test
{
    public function testFoo()
    {
        echo "Test::testFoo()\n";
    }
}

class TestService
{
    public function testServiceFoo()
    {
        echo "TestService::testServiceFoo()\n";
    }
}

class Foo
{
    public function bar($thoughts, array $bar = [])
    {
        echo "Foo::bar()\n";
        return [
            'thoughts' => $thoughts,
            'first' => isset($bar[0]) ? $bar[0] : '(none)',
            'rand' => mt_rand(42, 999)
        ];
    }
}

var_dump(dd_trace_method('Test', 'testFoo', function (SpanData $span) {
    $span->name = 'TestFoo';
}));
var_dump(dd_trace_method('TestService', 'testServiceFoo', null));
var_dump(dd_trace_method(
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
            'foo' => isset($args[1][1]) ? $args[1][1] : '',
            'bar' => isset($args[1][2]) ? $args[1][2] : '',
        ];
    }
));
var_dump(dd_trace_function('mt_rand', function (SpanData $span, $args, $retval) {
    $span->name = 'MT';
    $span->meta = [
        'rand.range' => $args[0] . ' - ' . $args[1],
        'rand.value' => $retval,
    ];
}));

$test = new Test();
$test->testFoo();

$testService = new TestService();
$testService->testServiceFoo();

$foo = new Foo();
$ret = $foo->bar('tracing is awesome', ['first', 'foo-red', 'bar-green']);
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
Test::testFoo()
TestService::testServiceFoo()
Foo::bar()
array(3) {
  ["thoughts"]=>
  string(18) "tracing is awesome"
  ["first"]=>
  string(5) "first"
  ["rand"]=>
  int(%d)
}
---
array(3) {
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
    string(7) "FooName"
    ["resource"]=>
    string(11) "FooResource"
    ["service"]=>
    string(10) "FooService"
    ["type"]=>
    string(7) "FooType"
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
  array(7) {
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
    ["meta"]=>
    array(2) {
      ["rand.range"]=>
      string(8) "42 - 999"
      ["rand.value"]=>
      int(%d)
    }
  }
  [2]=>
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
