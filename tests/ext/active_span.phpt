--TEST--
DDTrace\active_span basic functionality
--SKIPIF--
<?php
if (version_compare(PHP_VERSION, '8.0.0', '<'))
    die('skip: test only works in PHP 8.0+');
# In 7.4 and before, the way the SpanData class is registered in C is different compared to 8.0+, therefore the
# 'resource' and 'exception' properties won't be dumped
?>
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
object(DDTrace\SpanData)#%d (8) {
  ["name"]=>
  string(15) "active_span.php"
  ["resource"]=>
  uninitialized(?string)
  ["service"]=>
  string(15) "active_span.php"
  ["type"]=>
  string(3) "cli"
  ["meta"]=>
  array(0) {
  }
  ["metrics"]=>
  array(1) {
    ["process_id"]=>
    float(%f)
  }
  ["exception"]=>
  uninitialized(?Throwable)
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
bool(true)
