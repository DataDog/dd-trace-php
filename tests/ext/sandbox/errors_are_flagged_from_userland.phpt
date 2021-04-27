--TEST--
Errors from userland will be flagged on span
--FILE--
<?php
use DDTrace\SpanData;

function testErrorFromUserland()
{
    echo "testErrorFromUserland()\n";
}

DDTrace\trace_function('testErrorFromUserland', function (SpanData $span) {
    $span->name = 'testErrorFromUserland';
    $span->meta = ['error.msg' => 'Foo error'];
});

testErrorFromUserland();

var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECTF--
testErrorFromUserland()
array(1) {
  [0]=>
  array(8) {
    ["trace_id"]=>
    int(%d)
    ["span_id"]=>
    int(%d)
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(21) "testErrorFromUserland"
    ["resource"]=>
    string(21) "testErrorFromUserland"
    ["error"]=>
    int(1)
    ["meta"]=>
    array(2) {
      ["error.msg"]=>
      string(9) "Foo error"
      ["system.pid"]=>
      string(%d) "%d"
    }
  }
}
