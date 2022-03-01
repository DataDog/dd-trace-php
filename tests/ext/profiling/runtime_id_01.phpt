--TEST--
runtime-id exists in meta when both projects are 
--ENV--
DD_SERVICE=phpt
DD_PROFILING_ENABLED=true
DD_TRACE_CLI_ENABLED=true
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo("skip: requires profiling");
?>
--FILE--
<?php

var_dump(DDTrace\active_span()); // dump the root span

?>
--EXPECTF--
object(DDTrace\SpanData)#%d (6) {
  ["name"]=>
  string(%d) "runtime_id_01.php"
  ["service"]=>
  string(4) "phpt"
  ["type"]=>
  string(3) "cli"
  ["meta"]=>
  array(2) {
    ["system.pid"]=>
    int(%d)
    ["runtime-id"]=>
    string(%d) "%s"
  }
  ["metrics"]=>
  array(0) {
  }
  ["id"]=>
  string(%d) "%d"
}
