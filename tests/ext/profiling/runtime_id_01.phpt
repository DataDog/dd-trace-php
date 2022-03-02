--TEST--
runtime-id exists in meta when profiling is enabled
--ENV--
DD_PROFILING_ENABLED=true
DD_TRACE_CLI_ENABLED=true
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
--EXPECT--
bool(true)
