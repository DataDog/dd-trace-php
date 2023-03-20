--TEST--
Inherit some global metadata from parent span
--INI--
datadog.trace.generate_root_span=0
datadog.env = badenv
datadog.version = badversion
--ENV--
HTTP_X_DATADOG_ORIGIN=badorigin
--FILE--
<?php

$span = \DDTrace\start_span();
$span->meta["version"] = "goodversion";
$span->meta["env"] = "goodenv";
$span->meta["_dd.origin"] = "goodorigin";

\DDTrace\start_span();
\DDTrace\close_span();

\DDTrace\close_span();

var_dump(array_intersect_key(dd_trace_serialize_closed_spans()[1]["meta"], [
    "_dd.origin" => 1,
    "env" => 1,
    "version" => 1,
]));

?>
--EXPECT--
array(3) {
  ["version"]=>
  string(11) "goodversion"
  ["env"]=>
  string(7) "goodenv"
  ["_dd.origin"]=>
  string(10) "goodorigin"
}
