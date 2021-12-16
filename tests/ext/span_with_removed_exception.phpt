--TEST--
Unset, nulled and generally invalid data in exception property is ignored
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

function test() {
    throw new \Exception;
}

DDTrace\trace_function("test", function($span) {
    $span->exception = new \stdClass;
});

try {
    test();
} catch (Exception $e) {
    var_dump(dd_trace_serialize_closed_spans());
}

DDTrace\trace_function("test", function($span) {
    $span->exception = null;
});

try {
    test();
} catch (Exception $e) {
    var_dump(dd_trace_serialize_closed_spans());
}

DDTrace\trace_function("test", function($span) {
    unset($span->exception);
});

try {
    test();
} catch (Exception $e) {
    var_dump(dd_trace_serialize_closed_spans());
}

?>
--EXPECTF--
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
    string(4) "test"
    ["resource"]=>
    string(4) "test"
    ["service"]=>
    string(31) "span_with_removed_exception.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(1) {
      ["system.pid"]=>
      string(%d) "%d"
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
    string(4) "test"
    ["resource"]=>
    string(4) "test"
    ["service"]=>
    string(31) "span_with_removed_exception.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(1) {
      ["system.pid"]=>
      string(%d) "%d"
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
    string(4) "test"
    ["resource"]=>
    string(4) "test"
    ["service"]=>
    string(31) "span_with_removed_exception.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(1) {
      ["system.pid"]=>
      string(%d) "%d"
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
