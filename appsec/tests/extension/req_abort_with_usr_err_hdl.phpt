--TEST--
Request abort warning does not trigger user error handler
--FILE--
<?php
function error_handler($errno, $errstr, $errfile, $errline) {
	echo "NOT EXPECTED FOR THIS TO BE CALLED\n";
}
\datadog\appsec\testing\abort_static_page();
?>
THIS SHOULD NOT BE REACHED
--EXPECTF--
%s
Warning: datadog\appsec\testing\abort_static_page(): Datadog blocked the request and presented a static error page - block_id:  in %s on line %d
