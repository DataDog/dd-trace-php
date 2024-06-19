--TEST--
Number of frames can be configured
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_MAX_STACK_TRACE_DEPTH=2
--FILE--
<?php

use function datadog\appsec\testing\generate_backtrace;

function two($param01, $param02)
{
    var_dump(generate_backtrace());
}

function one($param01)
{
    two($param01, "other");
}

one("foo");

?>
--EXPECTF--
array(2) {
  [0]=>
  array(4) {
    ["line"]=>
    int(7)
    ["function"]=>
    string(41) "datadog\appsec\testing\generate_backtrace"
    ["file"]=>
    string(%d) "generate_backtrace_03.php"
    ["id"]=>
    int(0)
  }
  [1]=>
  array(4) {
    ["line"]=>
    int(12)
    ["function"]=>
    string(3) "two"
    ["file"]=>
    string(%d) "generate_backtrace_03.php"
    ["id"]=>
    int(1)
  }
}