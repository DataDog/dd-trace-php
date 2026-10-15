--TEST--
Clone DDTrace\SpanData
--SKIPIF--
<?php if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %r"); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
include 'dd_dumper.inc';
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

var_dump(dd_clean_spans());

?>
--EXPECTF--
object(DDTrace\RootSpanData)#%d (28) {
  ["name"]=>
  string(3) "foo"
  ["resource"]=>
  string(3) "abc"
  ["service"]=>
  string(14) "span_clone.php"
  ["meta_struct"]=>
  array(0) {
  }
  ["type"]=>
  string(3) "cli"
  ["meta"]=>
  array(5) {
    ["runtime-id"]=>
    string(36) "%s"
    ["service.name"]=>
    string(14) "span_clone.php"
    ["resource.name"]=>
    string(3) "abc"
    ["operation.name"]=>
    string(3) "foo"
    ["span.type"]=>
    string(3) "cli"
  }
  ["metrics"]=>
  array(1) {
    ["process_id"]=>
    int(%d)
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
  object(DDTrace\SpanStack)#%d (3) {
    ["parent"]=>
    object(DDTrace\SpanStack)#%d (3) {
      ["parent"]=>
      NULL
      ["active"]=>
      NULL
      ["spanCreationObservers"]=>
      array(0) {
      }
    }
    ["active"]=>
    *RECURSION*
    ["spanCreationObservers"]=>
    array(0) {
    }
  }
  ["onClose"]=>
  array(0) {
  }
  ["baggage"]=>
  array(0) {
  }
  ["env"]=>
  string(0) ""
  ["version"]=>
  string(0) ""
  ["component"]=>
  string(0) ""
  ["spanKind"]=>
  int(0)
  ["attributes"]=>
  array(2) {
    ["runtime-id"]=>
    string(36) "%s"
    ["process_id"]=>
    int(%d)
  }
  ["ignoreError"]=>
  bool(false)%r(\s*\["origin"\]=>\s+uninitialized\(string\))?%r
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
  ["inferredSpan"]=>
  NULL
}
object(DDTrace\RootSpanData)#%d (28) {
  ["name"]=>
  string(5) "dummy"
  ["resource"]=>
  string(3) "abc"
  ["service"]=>
  string(14) "span_clone.php"
  ["meta_struct"]=>
  array(0) {
  }
  ["type"]=>
  string(3) "cli"
  ["meta"]=>
  array(5) {
    ["runtime-id"]=>
    string(36) "%s"
    ["service.name"]=>
    string(14) "span_clone.php"
    ["resource.name"]=>
    string(3) "abc"
    ["operation.name"]=>
    string(5) "dummy"
    ["span.type"]=>
    string(3) "cli"
  }
  ["metrics"]=>
  array(1) {
    ["process_id"]=>
    int(%d)
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
  object(DDTrace\SpanStack)#%d (3) {
    ["parent"]=>
    object(DDTrace\SpanStack)#%d (3) {
      ["parent"]=>
      NULL
      ["active"]=>
      NULL
      ["spanCreationObservers"]=>
      array(0) {
      }
    }
    ["active"]=>
    object(DDTrace\RootSpanData)#%d (28) {
      ["name"]=>
      string(3) "foo"
      ["resource"]=>
      string(3) "abc"
      ["service"]=>
      string(14) "span_clone.php"
      ["meta_struct"]=>
      array(0) {
      }
      ["type"]=>
      string(3) "cli"
      ["meta"]=>
      array(5) {
        ["runtime-id"]=>
        string(36) "%s"
        ["service.name"]=>
        string(14) "span_clone.php"
        ["resource.name"]=>
        string(3) "abc"
        ["operation.name"]=>
        string(3) "foo"
        ["span.type"]=>
        string(3) "cli"
      }
      ["metrics"]=>
      array(1) {
        ["process_id"]=>
        int(%d)
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
      *RECURSION*
      ["onClose"]=>
      array(0) {
      }
      ["baggage"]=>
      array(0) {
      }
      ["env"]=>
      string(0) ""
      ["version"]=>
      string(0) ""
      ["component"]=>
      string(0) ""
      ["spanKind"]=>
      int(0)
      ["attributes"]=>
      array(2) {
        ["runtime-id"]=>
        string(36) "%s"
        ["process_id"]=>
        int(%d)
      }
      ["ignoreError"]=>
      bool(false)%r(\s*\["origin"\]=>\s+uninitialized\(string\))?%r
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
      ["inferredSpan"]=>
      NULL
    }
    ["spanCreationObservers"]=>
    array(0) {
    }
  }
  ["onClose"]=>
  array(0) {
  }
  ["baggage"]=>
  array(0) {
  }
  ["env"]=>
  string(0) ""
  ["version"]=>
  string(0) ""
  ["component"]=>
  string(0) ""
  ["spanKind"]=>
  int(0)
  ["attributes"]=>
  array(2) {
    ["runtime-id"]=>
    string(36) "%s"
    ["process_id"]=>
    int(%d)
  }
  ["ignoreError"]=>
  bool(false)%r(\s*\["origin"\]=>\s+uninitialized\(string\))?%r
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
  ["inferredSpan"]=>
  NULL
}
array(1) {
  [0]=>
  array(8) {
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
    ["attributes"]=>
    array(6) {
      ["runtime-id"]=>
      string(36) "%s"
      ["process_id"]=>
      int(%d)
      ["_dd.agent_psr"]=>
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
