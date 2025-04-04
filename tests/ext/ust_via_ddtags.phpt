--TEST--
Test that service tags in DD_TAGS are ignored but version and env tags are set
--ENV--
DD_VERSION=
DD_ENV=
DD_TAGS=service:ddtags_service,env:ddtags_env,version:ddtags_version
--FILE--
<?php

// Start first span
$span1 = DDTrace\start_span();
$span1->name = "span1";

// Start second span
$span2 = DDTrace\start_span();
$span2->name = "span2";

// Close spans
DDTrace\close_span();
DDTrace\close_span();

// Get the spans and verify both
$spans = dd_trace_serialize_closed_spans();
if (count($spans) >= 2) {
    var_dump([
        'span1' => [
            'name' => $spans[0]['name'],
            'service' => $spans[0]['service'],
            'version' => $spans[0]['meta']['version'],
            'env' => $spans[0]['meta']['env']
        ],
        'span2' => [
            'name' => $spans[1]['name'],
            'service' => $spans[1]['service'],
            'version' => $spans[1]['meta']['version'],
            'env' => $spans[1]['meta']['env']
        ]
    ]);
}
?>
--EXPECTF--
array(2) {
  ["span1"]=>
  array(4) {
    ["name"]=>
    string(5) "span1"
    ["service"]=>
    string(%d) "%r(?!ddtags_service$).+%r"
    ["version"]=>
    string(14) "ddtags_version"
    ["env"]=>
    string(10) "ddtags_env"
  }
  ["span2"]=>
  array(4) {
    ["name"]=>
    string(5) "span2"
    ["service"]=>
    string(%d) "%r(?!ddtags_service$).+%r"
    ["version"]=>
    string(14) "ddtags_version"
    ["env"]=>
    string(10) "ddtags_env"
  }
}
