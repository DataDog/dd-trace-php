--TEST--
Unset, nulled and generally invalid data in exception property is ignored
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires improved exception handling'); ?>
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
  array(8) {
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
array(1) {
  [0]=>
  array(8) {
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
array(1) {
  [0]=>
  array(8) {
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
