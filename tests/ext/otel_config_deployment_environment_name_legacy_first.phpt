--TEST--
Test stable OpenTelemetry deployment environment takes precedence when listed after legacy
--ENV--
OTEL_RESOURCE_ATTRIBUTES=foo=bar,deployment.environment=legacy,deployment.environment.name=stable,xyz=abc
--FILE--
<?php

var_dump(ini_get("datadog.env"));
var_dump(ini_get("datadog.tags"));

?>
--EXPECT--
string(6) "stable"
string(15) "foo:bar,xyz:abc"
