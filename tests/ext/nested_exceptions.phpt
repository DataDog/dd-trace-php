--TEST--
Nested exceptions are recorded (GH2498)
--FILE--
<?php

$nestedException = new \RuntimeException('Some kind of message');
$nestedException = new \LogicException('An error message', 456, $nestedException);
$nestedException = new \Exception('This is a generic exception message', 123, $nestedException);

$span = \DDTrace\start_span();
$span->exception = $nestedException;
\DDTrace\close_span();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
array(1) {
  [0]=>
  array(11) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["parent_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(0) ""
    ["resource"]=>
    string(0) ""
    ["service"]=>
    string(21) "nested_exceptions.php"
    ["type"]=>
    string(3) "cli"
    ["error"]=>
    int(1)
    ["meta"]=>
    array(3) {
      ["error.message"]=>
      string(%d) "Thrown RuntimeException: Some kind of message in %s:%d"
      ["error.stack"]=>
      string(%d) "#0 {main}

Next LogicException: An error message in %s:%d
Stack trace:
#0 {main}

Next Exception: This is a generic exception message in %s:%d
Stack trace:
#0 {main}"
      ["error.type"]=>
      string(16) "RuntimeException"
    }
  }
}
