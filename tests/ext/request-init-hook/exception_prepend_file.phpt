--TEST--
Gracefully handle exceptions in auto_prepend_file
--INI--
auto_prepend_file={PWD}/auto_prepend_file_exception.inc
ddtrace.request_init_hook={PWD}/../includes/request_init_hook.inc
--FILE--
<?php

// This should not be even invoked, but due to a bug it was. And when the exception handler got set, it segfaulted.
set_exception_handler(function() {});
echo "Unreachable\n";

?>
--EXPECTF--
Calling ddtrace_init()...
Called dd_init.php

Fatal error: Uncaught Exception in %s:3
Stack trace:
#0 {main}
  thrown in %s/auto_prepend_file_exception.inc on line 3
