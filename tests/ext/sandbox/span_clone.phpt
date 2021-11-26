--TEST--
Clone DDTrace\SpanData
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use DDTrace\SpanData;

function dummy() { }

DDTrace\trace_function('dummy', function (SpanData $span) {
    $span->resource = "abc";
    $span_copy = clone $span;
    $span->name = "foo";
    var_dump($span);
    var_dump($span_copy);
});

dummy();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
object(DDTrace\SpanData)#%d (8) {
  ["name"]=>
  string(3) "foo"
  ["resource"]=>
  string(3) "abc"
  ["service"]=>
  string(14) "span_clone.php"
  ["type"]=>
  string(3) "cli"
  ["meta"]=>
  array(1) {
    ["system.pid"]=>
    int(%d)
  }
  ["metrics"]=>
  array(0) {
  }
  ["exception"]=>
  NULL
  ["parent"]=>
  NULL
}
object(DDTrace\SpanData)#%d (8) {
  ["name"]=>
  string(5) "dummy"
  ["resource"]=>
  string(3) "abc"
  ["service"]=>
  string(14) "span_clone.php"
  ["type"]=>
  string(3) "cli"
  ["meta"]=>
  array(1) {
    ["system.pid"]=>
    int(%d)
  }
  ["metrics"]=>
  array(0) {
  }
  ["exception"]=>
  NULL
  ["parent"]=>
  NULL
}
array(1) {
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
    string(3) "foo"
    ["resource"]=>
    string(3) "abc"
    ["service"]=>
    string(14) "span_clone.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(1) {
      ["system.pid"]=>
      string(%d) "%d"
    }
    ["metrics"]=>
    array(1) {
      ["php.compilation.total_time_ms"]=>
      float(%f)
    }
  }
}
