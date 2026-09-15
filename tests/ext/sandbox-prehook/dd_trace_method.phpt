--TEST--
[Prehook Regression] DDTrace\trace_method() can trace with internal spans
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=mt_rand
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
include __DIR__ . '/../sandbox/dd_dumper.inc';
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

DDTrace\trace_method('Test', 'testFoo', ['prehook' => function (SpanData $span) {
    $span->name = 'TestFoo';
}]);
DDTrace\trace_method(
    'Foo', 'bar',
    ['prehook' => function (SpanData $span, $args) {
        $span->name = 'FooName';
        $span->resource = 'FooResource';
        $span->service = 'FooService';
        $span->type = 'FooType';
        $span->meta += [
            'args.0' => isset($args[0]) ? $args[0] : '',
        ];
        $span->metrics += [
            'foo' => isset($args[1][1]) ? $args[1][1] : '',
            'bar' => isset($args[1][2]) ? $args[1][2] : '',
        ];
    }]
);
DDTrace\trace_function('mt_rand', ['prehook' => function (SpanData $span, $args) {
    $span->name = 'MT';
    $span->meta = [
        'rand.range' => $args[0] . ' - ' . $args[1],
    ];
}]);

$test = new Test();
$test->testFoo();

$testService = new TestService();
$testService->testServiceFoo();

$foo = new Foo();
$ret = $foo->bar('tracing is awesome', ['first', '100', false]);
var_dump($ret);

echo "---\n";

var_dump(dd_clean_spans());
var_dump(dd_clean_spans());
?>
--EXPECTF--
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
  array(13) {
    ["trace_id"]=>
    string(%d) "%d"
    ["trace_id_high"]=>
    string(16) "%s"
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
    ["span_kind"]=>
    int(1)
    ["sampling_priority"]=>
    int(1)
    ["sampling_mechanism"]=>
    int(0)
    ["attributes"]=>
    array(10) {
      ["runtime-id"]=>
      string(36) "%s"
      ["_dd.svc_src"]=>
      string(1) "m"
      ["args.0"]=>
      string(18) "tracing is awesome"
      ["process_id"]=>
      float(%f)
      ["foo"]=>
      float(100)
      ["bar"]=>
      float(0)
      ["_dd.agent_psr"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%f)
      ["php.memory.peak_usage_bytes"]=>
      float(%f)
      ["php.memory.peak_real_usage_bytes"]=>
      float(%f)
    }
  }
  [1]=>
  array(14) {
    ["trace_id"]=>
    string(%d) "%d"
    ["trace_id_high"]=>
    string(16) "%s"
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
    string(10) "FooService"
    ["type"]=>
    string(7) "FooType"
    ["span_kind"]=>
    int(1)
    ["sampling_priority"]=>
    int(1)
    ["sampling_mechanism"]=>
    int(0)
    ["attributes"]=>
    array(1) {
      ["rand.range"]=>
      string(8) "42 - 999"
    }
  }
  [2]=>
  array(13) {
    ["trace_id"]=>
    string(%d) "%d"
    ["trace_id_high"]=>
    string(16) "%s"
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
    ["span_kind"]=>
    int(1)
    ["sampling_priority"]=>
    int(1)
    ["sampling_mechanism"]=>
    int(0)
    ["attributes"]=>
    array(6) {
      ["runtime-id"]=>
      string(36) "%s"
      ["process_id"]=>
      float(%f)
      ["_dd.agent_psr"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%f)
      ["php.memory.peak_usage_bytes"]=>
      float(%f)
      ["php.memory.peak_real_usage_bytes"]=>
      float(%f)
    }
  }
}
array(0) {
}
