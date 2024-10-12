--TEST--
Unset, nulled and generally invalid data in exception property is ignored
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--SKIPIF--
<?php
if (version_compare(PHP_VERSION, '7.4.0', '>='))
    die('skip: test only works before 7.4');
# In 7.4+, an error would be caught by trying to assign an stdClass object to the exception property, since it
# expects a ?Throwable
?>
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
    array(3) {
      ["runtime-id"]=>
      string(36) "%s"
      ["_dd.p.dm"]=>
      string(2) "-0"
      ["_dd.p.tid"]=>
      string(16) "%s"
    }
    ["metrics"]=>
    array(6) {
      ["process_id"]=>
      float(%f)
      ["_dd.agent_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
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
    array(3) {
      ["runtime-id"]=>
      string(36) "%s"
      ["_dd.p.dm"]=>
      string(2) "-0"
      ["_dd.p.tid"]=>
      string(16) "%s"
    }
    ["metrics"]=>
    array(6) {
      ["process_id"]=>
      float(%f)
      ["_dd.agent_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
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
    array(3) {
      ["runtime-id"]=>
      string(36) "%s"
      ["_dd.p.dm"]=>
      string(2) "-0"
      ["_dd.p.tid"]=>
      string(16) "%s"
    }
    ["metrics"]=>
    array(6) {
      ["process_id"]=>
      float(%f)
      ["_dd.agent_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
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
