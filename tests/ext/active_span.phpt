--TEST--
DDTrace\active_span basic functionality
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
object(DDTrace\RootSpanData)#%d (12) {
  ["name"]=>
  string(15) "active_span.php"
  ["resource"]=>
  string(0) ""
  ["service"]=>
  string(15) "active_span.php"
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
  }
}
bool(true)
