--TEST--
By default DD_APPSEC_MAX_STACK_TRACE_DEPTH is 32
--INI--
extension=ddtrace.so
--FILE--
<?php

use function datadog\appsec\testing\generate_backtrace;

function recursive_function($limit)
{
    if (--$limit == 0) {
        var_dump(count(generate_backtrace()["exploit"][0]["frames"]));
        return;
    }

    recursive_function($limit);
}

recursive_function(40);

?>
--EXPECTF--
int(32)