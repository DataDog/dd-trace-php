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
object(DDTrace\SpanData)#%d (9) {
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
    ["runtime-id"]=>
    string(36) "%s"
  }
  ["metrics"]=>
  array(1) {
    ["process_id"]=>
    float(%f)
  }
  ["id"]=>
  string(%d) "%d"
  ["parent"]=>
  NULL
  ["stack"]=>
  object(DDTrace\SpanStack)#%d (2) {
    ["parent"]=>
    object(DDTrace\SpanStack)#%d (2) {
      ["parent"]=>
      NULL
      ["active"]=>
      NULL
    }
    ["active"]=>
    *RECURSION*
  }
}
object(DDTrace\SpanData)#%d (9) {
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
    ["runtime-id"]=>
    string(36) "%s"
  }
  ["metrics"]=>
  array(1) {
    ["process_id"]=>
    float(%f)
  }
  ["id"]=>
  string(%d) "%d"
  ["parent"]=>
  NULL
  ["stack"]=>
  object(DDTrace\SpanStack)#%d (2) {
    ["parent"]=>
    object(DDTrace\SpanStack)#%d (2) {
      ["parent"]=>
      NULL
      ["active"]=>
      NULL
    }
    ["active"]=>
    object(DDTrace\SpanData)#%d (9) {
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
        ["runtime-id"]=>
        string(36) "%s"
      }
      ["metrics"]=>
      array(1) {
        ["process_id"]=>
        float(%f)
      }
      ["id"]=>
      string(%d) "%d"
      ["parent"]=>
      NULL
      ["stack"]=>
      *RECURSION*
    }
  }
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
    array(2) {
      ["runtime-id"]=>
      string(36) "%s"
      ["_dd.p.dm"]=>
      string(2) "-1"
    }
    ["metrics"]=>
    array(4) {
      ["process_id"]=>
      float(%f)
      ["_dd.rule_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%f)
    }
  }
}
