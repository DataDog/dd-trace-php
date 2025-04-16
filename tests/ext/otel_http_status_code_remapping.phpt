--TEST--
Remap http.status_code metric to http.status_code meta - OTel HTTP Semantic Convention < 1.21.0
--FILE--
<?php

$span = \DDTrace\start_span();
$span->metrics['http.status_code'] = "200";
\DDTrace\close_span();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
array(1) {
  [0]=>
  array(10) {
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
    string(35) "otel_http_status_code_remapping.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(1) {
      ["http.status_code"]=>
      string(3) "200"
    }
  }
}
