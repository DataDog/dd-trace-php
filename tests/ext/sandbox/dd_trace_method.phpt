--TEST--
DDTrace\trace_method() can trace with internal spans
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=mt_rand
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

var_dump(DDTrace\trace_method('Test', 'testFoo', function (SpanData $span) {
    $span->name = 'TestFoo';
}));
var_dump(DDTrace\trace_method('TestService', 'testServiceFoo', null));
var_dump(DDTrace\trace_method(
    'Foo', 'bar',
    function (SpanData $span, $args, $retval) {
        $span->name = 'FooName';
        $span->resource = 'FooResource';
        $span->service = 'FooService';
        $span->type = 'FooType';
        $span->meta += [
            'args.0' => isset($args[0]) ? $args[0] : '',
            'retval.thoughts' => isset($retval['thoughts']) ? $retval['thoughts'] : '',
            'retval.first' => isset($retval['first']) ? $retval['first'] : '',
            'retval.rand' => isset($retval['rand']) ? $retval['rand'] : '',
        ];
        $span->metrics += [
            'foo' => isset($args[1][1]) ? $args[1][1] : '',
            'bar' => isset($args[1][2]) ? $args[1][2] : '',
        ];
    }
));
var_dump(DDTrace\trace_function('mt_rand', function (SpanData $span, $args, $retval) {
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
$ret = $foo->bar('tracing is awesome', ['first', '100', false]);
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
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
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
    array(7) {
      ["_dd.p.dm"]=>
      string(2) "-0"
      ["_dd.p.tid"]=>
      string(16) "%s"
      ["args.0"]=>
      string(18) "tracing is awesome"
      ["retval.first"]=>
      string(5) "first"
      ["retval.rand"]=>
      string(%d) "%d"
      ["retval.thoughts"]=>
      string(18) "tracing is awesome"
      ["runtime-id"]=>
      string(36) "%s"
    }
    ["metrics"]=>
    array(8) {
      ["_dd.agent_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
      ["bar"]=>
      float(0)
      ["foo"]=>
      float(100)
      ["php.compilation.total_time_ms"]=>
      float(%f)
      ["php.memory.peak_real_usage_bytes"]=>
      float(%f)
      ["php.memory.peak_usage_bytes"]=>
      float(%f)
      ["process_id"]=>
      float(%f)
    }
  }
  [1]=>
  array(10) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["parent_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(2) "MT"
    ["resource"]=>
    string(2) "MT"
    ["service"]=>
    string(19) "dd_trace_method.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(3) {
      ["_dd.base_service"]=>
      string(10) "FooService"
      ["rand.range"]=>
      string(8) "42 - 999"
      ["rand.value"]=>
      string(%d) "%d"
    }
  }
  [2]=>
  array(10) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(7) "TestFoo"
    ["resource"]=>
    string(7) "TestFoo"
    ["service"]=>
    string(19) "dd_trace_method.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(3) {
      ["_dd.p.dm"]=>
      string(2) "-0"
      ["_dd.p.tid"]=>
      string(16) "%s"
      ["runtime-id"]=>
      string(36) "%s"
    }
    ["metrics"]=>
    array(6) {
      ["_dd.agent_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%f)
      ["php.memory.peak_real_usage_bytes"]=>
      float(%f)
      ["php.memory.peak_usage_bytes"]=>
      float(%f)
      ["process_id"]=>
      float(%f)
    }
  }
}
array(0) {
}
