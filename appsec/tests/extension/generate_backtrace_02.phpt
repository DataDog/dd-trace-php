--TEST--
Generate backtrace is not generated when disabled
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_STACK_TRACE_ENABLED=false
--FILE--
<?php

use function datadog\appsec\testing\generate_backtrace;

function two($param01, $param02)
{
    var_dump(generate_backtrace());
}

function one($param01)
{
    two($param01, "other");
}

one("foo");

?>
--EXPECTF--
array(0) {
}