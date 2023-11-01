--TEST--
Runtime ID without DDTrace loaded
--FILE--
<?php
use function datadog\appsec\testing\get_formatted_runtime_id;

var_dump(get_formatted_runtime_id());
?>
--EXPECTF--
string(0) ""
