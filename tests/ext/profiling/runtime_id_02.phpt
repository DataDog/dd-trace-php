--TEST--
runtime-id exists in meta when profiling is disabled
--ENV--
DD_PROFILING_ENABLED=false
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo("skip: requires profiling");
?>
--FILE--
<?php

$meta = DDTrace\active_span()->meta;
var_dump(isset($meta['runtime-id']));

?>
--EXPECTF--
bool(true)
