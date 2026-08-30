--TEST--
runtime-id exists in meta when profiling is enabled
--ENV--
DD_PROFILING_ENABLED=true
--SKIPIF--
<?php
if (ini_get('datadog.profiling.enabled') === false)
    echo("skip: requires profiling support");
?>
--FILE--
<?php

$meta = DDTrace\active_span()->meta;
var_dump(isset($meta['runtime-id']));

?>
--EXPECT--
bool(true)
