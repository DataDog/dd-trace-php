--TEST--
Regression: ZEND_HASH_FOREACH_FROM end pointer must be arData+nNumUsed, not _p+nNumUsed
--SKIPIF--
<?php
if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    die('skip: custom ZEND_HASH_FOREACH_FROM only compiled for PHP < 8.1');
}
?>
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_MAX_STACK_TRACE_DEPTH=4
--FILE--
<?php

use function datadog\appsec\testing\generate_backtrace;

function recursive_function($limit)
{
    if (--$limit == 0) {
        var_dump(generate_backtrace("some id"));
        return;
    }

    recursive_function($limit);
}

recursive_function(8);

?>
--EXPECTF--
array(3) {
  ["language"]=>
  string(3) "php"
  ["id"]=>
  string(7) "some id"
  ["frames"]=>
  array(4) {
    [0]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_08.php"
      ["id"]=>
      int(0)
    }
    [1]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_08.php"
      ["id"]=>
      int(5)
    }
    [2]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_08.php"
      ["id"]=>
      int(6)
    }
    [3]=>
    array(4) {
      ["line"]=>
      int(15)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_08.php"
      ["id"]=>
      int(7)
    }
  }
}
