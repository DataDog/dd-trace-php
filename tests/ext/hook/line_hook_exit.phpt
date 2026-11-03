--TEST--
An open range still closes when exit() unwinds the frame
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function t() {
    $a = 1;
    exit(0);
}

// PHP 8 turns exit() into an unwind exception, so the frame leaves through the normal exception path; on PHP 7 it is a zend_bailout instead.
// Either way the range must close, and before the function's own end hook.
DDTrace\install_hook('t', null, function () { echo "fn:end\n"; });
DDTrace\install_line_hook(__FILE__, 4, function () { echo "line:begin\n"; }, 6, function () { echo "line:end\n"; });
t();
echo "unreachable\n";
?>
--EXPECT--
line:begin
line:end
fn:end
