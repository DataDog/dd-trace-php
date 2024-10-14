--TEST--
Clone DDTrace\SpanData
--SKIPIF--
<?php if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %r"); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
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
object(DDTrace\RootSpanData)#%d (21) {
  ["name"]=>
  string(3) "foo"
  ["resource"]=>
  string(3) "abc"
  ["service"]=>
  string(14) "span_clone.php"
  ["env"]=>
  string(0) ""
  ["version"]=>
  string(0) ""
  ["meta_struct"]=>
  array(0) {
  }
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
  ["exception"]=>
  NULL
  ["id"]=>
  string(%d) "%d"
  ["links"]=>
  array(0) {
  }
  ["events"]=>
  array(0) {
  }
  ["peerServiceSources"]=>
  array(0) {
  }
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
  }%r(\s*\["origin"\]=>\s+uninitialized\(string\))?%r
  ["propagatedTags"]=>
  array(0) {
  }
  ["samplingPriority"]=>
  int(1073741824)%r(\s*\["propagatedSamplingPriority"\]=>\s+uninitialized\(int\)\s*\["tracestate"\]=>\s+uninitialized\(string\))?%r
  ["tracestateTags"]=>
  array(0) {
  }%r(\s*\["parentId"\]=>\s+uninitialized\(string\))?%r
  ["traceId"]=>
  string(32) "%s"
  ["gitMetadata"]=>
  NULL
}
object(DDTrace\RootSpanData)#%d (21) {
  ["name"]=>
  string(5) "dummy"
  ["resource"]=>
  string(3) "abc"
  ["service"]=>
  string(14) "span_clone.php"
  ["env"]=>
  string(0) ""
  ["version"]=>
  string(0) ""
  ["meta_struct"]=>
  array(0) {
  }
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
  ["exception"]=>
  NULL
  ["id"]=>
  string(%d) "%d"
  ["links"]=>
  array(0) {
  }
  ["events"]=>
  array(0) {
  }
  ["peerServiceSources"]=>
  array(0) {
  }
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
    object(DDTrace\RootSpanData)#%d (21) {
      ["name"]=>
      string(3) "foo"
      ["resource"]=>
      string(3) "abc"
      ["service"]=>
      string(14) "span_clone.php"
      ["env"]=>
      string(0) ""
      ["version"]=>
      string(0) ""
      ["meta_struct"]=>
      array(0) {
      }
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
        float(%d)
      }
      ["exception"]=>
      NULL
      ["id"]=>
      string(%d) "%s"
      ["links"]=>
      array(0) {
      }
      ["events"]=>
      array(0) {
      }
      ["peerServiceSources"]=>
      array(0) {
      }
      ["parent"]=>
      NULL
      ["stack"]=>
      *RECURSION*%r(\s*\["origin"\]=>\s+uninitialized\(string\))?%r
      ["propagatedTags"]=>
      array(0) {
      }
      ["samplingPriority"]=>
      int(1073741824)%r(\s*\["propagatedSamplingPriority"\]=>\s+uninitialized\(int\)\s*\["tracestate"\]=>\s+uninitialized\(string\))?%r
      ["tracestateTags"]=>
      array(0) {
      }%r(\s*\["parentId"\]=>\s+uninitialized\(string\))?%r
      ["traceId"]=>
      string(32) "%s"
      ["gitMetadata"]=>
      NULL
    }
  }%r(\s*\["origin"\]=>\s+uninitialized\(string\))?%r
  ["propagatedTags"]=>
  array(0) {
  }
  ["samplingPriority"]=>
  int(1073741824)%r(\s*\["propagatedSamplingPriority"\]=>\s+uninitialized\(int\)\s*\["tracestate"\]=>\s+uninitialized\(string\))?%r
  ["tracestateTags"]=>
  array(0) {
  }%r(\s*\["parentId"\]=>\s+uninitialized\(string\))?%r
  ["traceId"]=>
  string(32) "%s"
  ["gitMetadata"]=>
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
