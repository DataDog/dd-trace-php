--TEST--
Call root_span_get_meta() without ddtrace loaded
--FILE--
<?php

namespace ddtrace;

use function \datadog\appsec\testing\root_span_get_meta;
var_dump(root_span_get_meta());
?>
--EXPECTF--
NULL
