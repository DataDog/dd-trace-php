--TEST--
Request abort from inside ob handler
--SKIPIF--
<?php
require __DIR__ . "/inc/no_valgrind.php";
?>
--FILE--
<?php
function error_handler($errno, $errstr, $errfile, $errline) {
	echo "NOT EXPECTED FOR THIS TO BE CALLED\n";
}
ob_start(function($buf, $phase) {
    \datadog\appsec\testing\abort_static_page();
});
?>
THIS SHOULD NOT BE OUTPUT
--EXPECTF--
%s
Warning: datadog\appsec\testing\abort_static_page(): Datadog blocked the request and presented a static error page. No action required. Block ID:  in %s on line %d
