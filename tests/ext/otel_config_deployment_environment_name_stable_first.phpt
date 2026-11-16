--TEST--
Test stable OpenTelemetry deployment environment takes precedence when listed first
--ENV--
OTEL_RESOURCE_ATTRIBUTES=foo=bar,deployment.environment.name=stable,deployment.environment=legacy,xyz=abc
--FILE--
<?php

var_dump(ini_get("datadog.env"));
var_dump(ini_get("datadog.tags"));

?>
--EXPECT--
string(6) "stable"
string(15) "foo:bar,xyz:abc"
