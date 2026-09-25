--TEST--
An open range still closes when a zend_bailout abandons the frame
--INI--
; E_USER_ERROR is deprecated from 8.4, and the notice is not what this test is about. 24575 = E_ALL & ~E_DEPRECATED.
error_reporting=24575
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function t() {
    $a = 1;
    trigger_error('boom', E_USER_ERROR);
}

// The end line has no opline after it, so this range has no static closing site and depends entirely on the frame-exit guard.
// E_USER_ERROR is a real zend_bailout, but php_request_shutdown() runs zend_observer_fcall_end_all() as its step 0 -- before zend_deactivate_modules() and so before ddtrace's RSHUTDOWN -- which is what makes the guard fire.
DDTrace\install_hook('t', null, function () { echo "fn:end\n"; });
DDTrace\install_line_hook(__FILE__, 4, function () { echo "line:begin\n"; }, 6, function () { echo "line:end\n"; });
t();
echo "unreachable\n";
?>
--EXPECTF--
line:begin

Fatal error: boom in %s on line 5
line:end
fn:end
