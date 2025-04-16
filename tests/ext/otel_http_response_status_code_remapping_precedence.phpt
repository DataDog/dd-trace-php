--TEST--
http.response.status_code takes precedence over http.status_code
--FILE--
<?php

$span = \DDTrace\start_span();
$span->metrics['http.response.status_code'] = 300;
$span->meta['http.status_code'] = 302;
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
    string(55) "otel_http_response_status_code_remapping_precedence.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(1) {
      ["http.status_code"]=>
      string(3) "300"
    }
  }
}
