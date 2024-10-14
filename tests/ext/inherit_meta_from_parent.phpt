--TEST--
Inherit some global metadata from parent span
--INI--
datadog.trace.generate_root_span=0
datadog.env = badenv
datadog.version = badversion
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php

$span = \DDTrace\start_span();
$span->meta["version"] = "goodversion";
$span->meta["env"] = "goodenv";

\DDTrace\start_span();
\DDTrace\close_span();

\DDTrace\close_span();

var_dump(array_intersect_key(dd_trace_serialize_closed_spans()[1]["meta"], [
    "env" => 1,
    "version" => 1,
]));

?>
--EXPECT--
array(2) {
  ["env"]=>
  string(7) "goodenv"
  ["version"]=>
  string(11) "goodversion"
}
