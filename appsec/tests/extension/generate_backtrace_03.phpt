--TEST--
Number of frames can be configured with DD_APPSEC_MAX_STACK_TRACE_DEPTH
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_MAX_STACK_TRACE_DEPTH=1
--FILE--
<?php

use function datadog\appsec\testing\generate_backtrace;

function two($param01, $param02)
{
    var_dump(generate_backtrace("some id"));
}

function one($param01)
{
    two($param01, "other");
}

one("foo");

?>
--EXPECTF--
array(3) {
  ["language"]=>
  string(3) "php"
  ["id"]=>
  string(7) "some id"
  ["frames"]=>
  array(1) {
    [0]=>
    array(4) {
      ["line"]=>
      int(15)
      ["function"]=>
      string(3) "one"
      ["file"]=>
      string(25) "generate_backtrace_03.php"
      ["id"]=>
      int(1)
    }
  }
}