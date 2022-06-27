--TEST--
Startup logging is disabled
--SKIPIF--
<?php include 'startup_logging_skipif.inc'; ?>
--ENV--
DD_TRACE_STARTUP_LOGS=0
--FILE--
<?php
include_once 'startup_logging.inc';
$logs = dd_get_startup_logs([], ['DD_TRACE_DEBUG=1']);

var_dump($logs);
?>
--EXPECTF--
%s/php-cgi %s
No JSON found: (%s)
array(0) {
}
