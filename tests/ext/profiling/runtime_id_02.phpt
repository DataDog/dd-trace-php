--TEST--
runtime-id exists in attributes when profiling is disabled
--ENV--
DD_PROFILING_ENABLED=false
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo("skip: requires profiling");
?>
--FILE--
<?php

$attributes = DDTrace\active_span()->attributes;
var_dump(isset($attributes['runtime-id']));

?>
--EXPECTF--
bool(true)
