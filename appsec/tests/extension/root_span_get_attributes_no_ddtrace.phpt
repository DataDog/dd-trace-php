--TEST--
Call root_span_get_attributes() without ddtrace loaded
--FILE--
<?php

namespace ddtrace;

use function \datadog\appsec\testing\root_span_get_attributes;
var_dump(root_span_get_attributes());
?>
--EXPECTF--
NULL
