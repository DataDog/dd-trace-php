--TEST--
Change the env and version INIs at runtime
--FILE--
<?php

var_dump(isset(\DDTrace\root_span()->meta["env"]), isset(\DDTrace\root_span()->meta["version"]));

ini_set("datadog.env", "test");
ini_set("datadog.version", "1.0.0");

echo "New env: ", \DDTrace\root_span()->meta["env"], "\n";
echo "New version: ", \DDTrace\root_span()->meta["version"], "\n";

?>
--EXPECT--
bool(false)
bool(false)
New env: test
New version: 1.0.0
