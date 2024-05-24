--TEST--
Change the env and version INIs at runtime
--FILE--
<?php

var_dump(\DDTrace\root_span()->env, \DDTrace\root_span()->version);

ini_set("datadog.env", "test");
ini_set("datadog.version", "1.0.0");

echo "New env: ", \DDTrace\root_span()->env, "\n";
echo "New version: ", \DDTrace\root_span()->version, "\n";

?>
--EXPECT--
string(0) ""
string(0) ""
New env: test
New version: 1.0.0
