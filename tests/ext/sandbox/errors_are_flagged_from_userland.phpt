--TEST--
Errors from userland will be flagged on span
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use DDTrace\SpanData;

function testErrorFromUserland()
{
    echo "testErrorFromUserland()\n";
}

DDTrace\trace_function('testErrorFromUserland', function (SpanData $span) {
    $span->name = 'testErrorFromUserland';
    $span->meta += ['error.msg' => 'Foo error'];
});

testErrorFromUserland();

var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECTF--
testErrorFromUserland()
array(1) {
  [0]=>
  array(11) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(21) "testErrorFromUserland"
    ["resource"]=>
    string(21) "testErrorFromUserland"
    ["service"]=>
    string(36) "errors_are_flagged_from_userland.php"
    ["type"]=>
    string(3) "cli"
    ["error"]=>
    int(1)
    ["meta"]=>
    array(2) {
      ["system.pid"]=>
      string(%d) "%d"
      ["error.msg"]=>
      string(9) "Foo error"
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
