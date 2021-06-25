--TEST--
DDTrace\add_global_tag() on all sorts of spans
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: Test requires internal spans'); ?>
--FILE--
<?php

$span = DDTrace\start_span();
$span->name = "polar " . ($_ = "bear");

function test($a) {
    return 'METHOD ' . $a;
}

DDTrace\trace_function("test", function($s, $a, $retval) {
    echo 'HOOK ' . $retval . PHP_EOL;
});

test("arg");

DDTrace\add_global_tag("alone", ($_ = "n") . "o");
DDTrace\add_global_tag("cubs", ($_ = "n") . "o");
DDTrace\add_global_tag("cubs", ($_ = "y") . "es"); // overwrites older entries

DDTrace\close_span();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
HOOK METHOD arg
array(2) {
  [0]=>
  array(8) {
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
    string(10) "polar bear"
    ["resource"]=>
    string(10) "polar bear"
    ["meta"]=>
    array(2) {
      ["alone"]=>
      string(2) "no"
      ["cubs"]=>
      string(3) "yes"
    }
  }
  [1]=>
  array(8) {
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
    string(4) "test"
    ["resource"]=>
    string(4) "test"
    ["meta"]=>
    array(2) {
      ["alone"]=>
      string(2) "no"
      ["cubs"]=>
      string(3) "yes"
    }
  }
}