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
object(DDTrace\SpanData)#%d (6) {
  ["name"]=>
  string(15) "active_span.php"
  ["service"]=>
  string(15) "active_span.php"
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
  ["id"]=>
  string(%d) "%d"
}
bool(true)
