--TEST--
Disabling root span removes the root span properly
--FILE--
<?php
ini_set("datadog.trace.generate_root_span", false);
var_dump(DDTrace\root_span());
?>
--EXPECT--
NULL