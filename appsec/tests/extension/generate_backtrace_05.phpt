--TEST--
DD_APPSEC_MAX_STACK_TRACE_DEPTH max value is 32 picked 24 from bottom and 8 from top
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
        var_dump(generate_backtrace());
        return;
    }

    recursive_function($limit);
}

recursive_function(40);

?>
--EXPECTF--
array(1) {
  ["exploit"]=>
  array(1) {
    [0]=>
    array(2) {
      ["language"]=>
      string(3) "php"
      ["frames"]=>
      array(32) {
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
          int(16)
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
          int(17)
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
          int(18)
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
          int(19)
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
          int(20)
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
          int(21)
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
          int(22)
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
          int(23)
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
          int(24)
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
          int(25)
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
          int(26)
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
          int(27)
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
          int(28)
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
          int(29)
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
          int(30)
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
          int(31)
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
          int(32)
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
          int(33)
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
          int(34)
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
          int(35)
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
          int(36)
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
          int(37)
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
          int(38)
        }
        [31]=>
        array(4) {
          ["line"]=>
          int(15)
          ["function"]=>
          string(18) "recursive_function"
          ["file"]=>
          string(25) "generate_backtrace_05.php"
          ["id"]=>
          int(39)
        }
      }
    }
  }
}