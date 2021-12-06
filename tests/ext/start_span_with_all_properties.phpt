--TEST--
Set DDTrace\start_span() properties
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
$span->meta += [($_ = "a") . "a" => ($_ = "b") . "b"];
$span->metrics += [($_ = "c") . "c" => ($_ = "d") . "d"];

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

$serialized = dd_trace_serialize_closed_spans();
$serialized[0]["duration"] += 500; // fix flakiness
var_dump($serialized);

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
  array(10) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(200000%d)
    ["name"]=>
    string(34) "start_span_with_all_properties.php"
    ["resource"]=>
    string(34) "start_span_with_all_properties.php"
    ["service"]=>
    string(34) "start_span_with_all_properties.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(2) {
      ["system.pid"]=>
      string(%d) "%d"
      ["_dd.p.upstream_services"]=>
      string(56) "c3RhcnRfc3Bhbl93aXRoX2FsbF9wcm9wZXJ0aWVzLnBocA|1|1|1.000"
    }
    ["metrics"]=>
    array(3) {
      ["_dd.rule_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
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
    array(3) {
      ["system.pid"]=>
      string(%d) "%d"
      ["aa"]=>
      string(2) "bb"
      ["_dd.p.upstream_services"]=>
      string(16) "dGVzdA|1|1|1.000"
    }
    ["metrics"]=>
    array(4) {
      ["cc"]=>
      float(0)
      ["_dd.rule_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%f)
    }
  }
}
