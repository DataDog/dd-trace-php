--TEST--
Generate backtrace
--INI--
extension=ddtrace.so
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
array(3) {
  [0]=>
  array(4) {
    ["line"]=>
    int(7)
    ["function"]=>
    string(41) "datadog\appsec\testing\generate_backtrace"
    ["file"]=>
    string(%d) "generate_backtrace_01.php"
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
    string(%d) "generate_backtrace_01.php"
    ["id"]=>
    int(1)
  }
  [2]=>
  array(4) {
    ["line"]=>
    int(15)
    ["function"]=>
    string(3) "one"
    ["file"]=>
    string(%d) "generate_backtrace_01.php"
    ["id"]=>
    int(2)
  }
}