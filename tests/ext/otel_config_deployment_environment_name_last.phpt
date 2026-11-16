--TEST--
Test stable OpenTelemetry deployment environment as the final resource attribute
--ENV--
OTEL_RESOURCE_ATTRIBUTES=foo=bar,deployment.environment.name=stable
--FILE--
<?php

var_dump(ini_get("datadog.env"));
var_dump(ini_get("datadog.tags"));

?>
--EXPECT--
string(6) "stable"
string(7) "foo:bar"
