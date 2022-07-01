--TEST--
Test invalid $_SERVER values are properly ignored
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
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
array(3) {
  ["system.pid"]=>
  string(%d) "%d"
  ["http.request.headers.0"]=>
  string(16) "http_zero_header"
  ["_dd.p.dm"]=>
  string(2) "-1"
}
