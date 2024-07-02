--TEST--
Generate backtrace
--INI--
extension=ddtrace.so
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
  array(2) {
    [0]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(3) "two"
      ["file"]=>
      string(25) "generate_backtrace_01.php"
      ["id"]=>
      int(0)
    }
    [1]=>
    array(4) {
      ["line"]=>
      int(15)
      ["function"]=>
      string(3) "one"
      ["file"]=>
      string(25) "generate_backtrace_01.php"
      ["id"]=>
      int(1)
    }
  }
}
