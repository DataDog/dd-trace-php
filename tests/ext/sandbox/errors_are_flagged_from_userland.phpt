--TEST--
Errors from userland will be flagged on span
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
use DDTrace\SpanData;

function testErrorFromUserland()
{
    echo "testErrorFromUserland()\n";
}

dd_trace_function('testErrorFromUserland', function (SpanData $span) {
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
  array(7) {
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
