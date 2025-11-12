--TEST--
Check that sandboxed hooks do not invoke error handlers or set the error code
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000 && getenv("SKIP_ASAN")) die("skip: Issue with passing ini to ASAN CGI on PHP 7.4"); ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: On Windows cgi skips stderr output'); ?>
--GET--
this+must+run+via+cgi
--INI--
datadog.trace.log_level=info,startup=off
--FILE--
<?php

set_error_handler(function() {
    echo "Unexpected Error handler invocation\n";
    return false;
});

function foo() { echo "foo\n"; }

DDTrace\trace_function("foo", function() {
    trigger_error("Fatal", E_USER_ERROR);
});
foo();

var_dump(http_response_code());

foo();

?>
--EXPECTF--
foo
[ddtrace] [warning] Error raised in ddtrace's closure defined at %s:%d for foo(): Fatal in %s on line %d
int(200)
foo
[ddtrace] [warning] Error raised in ddtrace's closure defined at %s:%d for foo(): Fatal in %s on line %d
[ddtrace] [info] Flushing trace of size %s
