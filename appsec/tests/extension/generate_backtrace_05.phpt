--TEST--
DD_APPSEC_MAX_STACK_TRACE_DEPTH can be set to unlimited with 0
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_MAX_STACK_TRACE_DEPTH=0
--FILE--
<?php

use function datadog\appsec\testing\generate_backtrace;

function recursive_function($limit)
{
    if (--$limit == 0) {
        var_dump(count(generate_backtrace("some id")["frames"]));
        return;
    }

    recursive_function($limit);
}

recursive_function(50);

?>
--EXPECTF--
int(50)