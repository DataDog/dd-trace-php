--TEST--
When DD_APPSEC_MAX_STACK_TRACE_DEPTH is lower than the number of frames. 0.25% are picked from top and 75% from  bottom
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_MAX_STACK_TRACE_DEPTH=40
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

recursive_function(50);

?>
--EXPECTF--
array(3) {
  ["language"]=>
  string(3) "php"
  ["id"]=>
  string(7) "some id"
  ["frames"]=>
  array(40) {
    [0]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
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
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(1)
    }
    [2]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(2)
    }
    [3]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(3)
    }
    [4]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(4)
    }
    [5]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(5)
    }
    [6]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(6)
    }
    [7]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(7)
    }
    [8]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(8)
    }
    [9]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(9)
    }
    [10]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(20)
    }
    [11]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(21)
    }
    [12]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(22)
    }
    [13]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(23)
    }
    [14]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(24)
    }
    [15]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(25)
    }
    [16]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(26)
    }
    [17]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(27)
    }
    [18]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(28)
    }
    [19]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(29)
    }
    [20]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(30)
    }
    [21]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(31)
    }
    [22]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(32)
    }
    [23]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(33)
    }
    [24]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(34)
    }
    [25]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(35)
    }
    [26]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(36)
    }
    [27]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(37)
    }
    [28]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(38)
    }
    [29]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(39)
    }
    [30]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(40)
    }
    [31]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(41)
    }
    [32]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(42)
    }
    [33]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(43)
    }
    [34]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(44)
    }
    [35]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(45)
    }
    [36]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(46)
    }
    [37]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(47)
    }
    [38]=>
    array(4) {
      ["line"]=>
      int(12)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(48)
    }
    [39]=>
    array(4) {
      ["line"]=>
      int(15)
      ["function"]=>
      string(18) "recursive_function"
      ["file"]=>
      string(25) "generate_backtrace_05.php"
      ["id"]=>
      int(49)
    }
  }
}
