--TEST--
runtime-id exists in attributes when profiling is enabled
--ENV--
DD_PROFILING_ENABLED=true
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
--EXPECT--
bool(true)
