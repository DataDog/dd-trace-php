--TEST--
DDTrace\active_span basic functionality
--SKIPIF--
<?php if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %r"); ?>
--FILE--
<?php

DDTrace\trace_function('greet',
    function ($span) {
        echo "greet tracer.\n";
        $span->name = "foo";
        var_dump($span == DDTrace\active_span());
    }
);

function greet($name)
{
    echo "Hello, {$name}.\n";
}

greet('Datadog');

var_dump(DDTrace\active_span());
var_dump(DDTrace\active_span() == DDTrace\active_span());

?>
--EXPECTF--
Hello, Datadog.
greet tracer.
bool(true)
object(DDTrace\RootSpanData)#%d (22) {
  ["name"]=>
  string(15) "active_span.php"
  ["resource"]=>
  string(0) ""
  ["service"]=>
  string(15) "active_span.php"
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
  ["inferredSpan"]=>
  NULL
}
bool(true)
