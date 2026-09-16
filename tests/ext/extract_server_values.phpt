--TEST--
Test invalid $_SERVER values are properly ignored
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
// The re-exec trick below needs the *full* original invocation (interpreter + its own -n/-d
// flags + script args, e.g. so the ddtrace extension is still loaded after re-exec), which only
// Linux's /proc/<pid>/cmdline exposes precisely and portably; macOS has no equivalent short of
// ext-ffi + sysctl(KERN_PROCARGS2).
if (PHP_OS === "Darwin") die('skip: re-exec via /proc/<pid>/cmdline is Linux-only');
?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_TRACE_HEADER_TAGS=0
HTTP_0=http_zero_header
--FILE--
<?php

// hack to squeeze a numerical env var into PHP
if (!isset($_SERVER[0])) {
    putenv("0=garbage");
    $cmdAndArgs = explode("\0", file_get_contents("/proc/" . getmypid() . "/cmdline"));
    pcntl_exec(array_shift($cmdAndArgs), $cmdAndArgs);
}

DDTrace\start_span();
DDTrace\close_span();
var_dump(dd_trace_serialize_closed_spans()[0]["meta"]);

?>
--EXPECTF--
array(5) {
  ["_dd.p.dm"]=>
  string(2) "-0"
  ["_dd.p.tid"]=>
  string(16) "%s"
  ["_dd.tags.process"]=>
  string(%d) "%s"
  ["http.request.headers.0"]=>
  string(16) "http_zero_header"
  ["runtime-id"]=>
  string(36) "%s"
}
