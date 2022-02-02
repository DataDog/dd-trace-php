--TEST--
[Prehook Regression] DDTrace\trace_method() can trace with internal spans
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=mt_rand
DD_TRACE_GENERATE_ROOT_SPAN=0
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

var_dump(dd_trace_serialize_closed_spans());
var_dump(dd_trace_serialize_closed_spans());
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
    array(3) {
      ["system.pid"]=>
      string(%d) "%d"
      ["args.0"]=>
      string(18) "tracing is awesome"
      ["_dd.p.upstream_services"]=>
      string(24) "Rm9vU2VydmljZQ|1|1|1.000"
    }
    ["metrics"]=>
    array(5) {
      ["foo"]=>
      float(100)
      ["bar"]=>
      float(0)
      ["_dd.rule_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
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
    string(10) "FooService"
    ["type"]=>
    string(7) "FooType"
    ["meta"]=>
    array(1) {
      ["rand.range"]=>
      string(8) "42 - 999"
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
    array(2) {
      ["system.pid"]=>
      string(%d) "%d"
      ["_dd.p.upstream_services"]=>
      string(36) "ZGRfdHJhY2VfbWV0aG9kLnBocA|1|1|1.000"
    }
    ["metrics"]=>
    array(3) {
      ["_dd.rule_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%f)
    }
  }
}
array(0) {
}
