--TEST--
Set DDTrace\start_span() properties
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: Test requires internal spans'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

$original_span = DDTrace\active_span();

$time = time();

$span = DDTrace\start_span();
// avoid interned strings
$span->name = ($_ = "d") . "ata";
$span->resource = ($_ = "d") . "og";
$span->service = ($_ = "t") . "est";
$span->type = ($_ = "r") . "unner";
$span->meta = [($_ = "a") . "a" => ($_ = "b") . "b"];
$span->metrics = [($_ = "c") . "c" => ($_ = "d") . "d"];

var_dump($span->getDuration());
var_dump($start_time = $span->getStartTime());
if ($start_time < $time * 1000000000 || $start_time > ($time + 2) * 1000000000) {
    echo "$start_time not in expected bounds of $time to $time + 2\n";
}

var_dump(DDTrace\active_span() == $span);

DDTrace\close_span();

var_dump($original_span == DDTrace\active_span());

var_dump($start_time = $span->getStartTime());
if ($start_time < $time * 1000000000 || $start_time > ($time + 2) * 1000000000) {
    echo "$start_time not in expected bounds of $time to $time + 2\n";
}
var_dump($span->getDuration() > 0);

$span = DDTrace\start_span();
DDTrace\close_span($span->getStartTime() / 1000000000 + 2.0000002); // nobody likes the limits of double precision
var_dump(round($span->getDuration(), -3));

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
int(0)
int(%d)
bool(true)
bool(true)
int(%d)
bool(true)
float(2000000000)
array(2) {
  [0]=>
  array(6) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(2000000%d)
    ["meta"]=>
    array(1) {
      ["system.pid"]=>
      string(%d) "%d"
    }
    ["metrics"]=>
    array(1) {
      ["php.compilation.total_time_ms"]=>
      float(%f)
    }
  }
  [1]=>
  array(10) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(4) "data"
    ["resource"]=>
    string(3) "dog"
    ["service"]=>
    string(4) "test"
    ["type"]=>
    string(6) "runner"
    ["meta"]=>
    array(2) {
      ["aa"]=>
      string(2) "bb"
      ["system.pid"]=>
      string(%d) "%d"
    }
    ["metrics"]=>
    array(2) {
      ["cc"]=>
      float(0)
      ["php.compilation.total_time_ms"]=>
      float(%f)
    }
  }
}
