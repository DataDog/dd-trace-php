--TEST--
Remap http.response.status_code to http.status_code - OTel HTTP Semantic Convention >= 1.21.0
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';

$span = \DDTrace\start_span();
$span->metrics['http.response.status_code'] = "300";
\DDTrace\close_span();

var_dump(dd_clean_spans());

?>
--EXPECTF--
array(1) {
  [0]=>
  array(12) {
    ["trace_id"]=>
    string(%d) "%d"
    ["trace_id_high"]=>
    string(16) "%s"
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
    string(44) "otel_http_response_status_code_remapping.php"
    ["type"]=>
    string(3) "cli"
    ["span_kind"]=>
    int(1)
    ["attributes"]=>
    array(1) {
      ["http.status_code"]=>
      string(3) "300"
    }
  }
}
