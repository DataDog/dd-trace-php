--TEST--
Test stable OpenTelemetry deployment environment config remapping
--ENV--
OTEL_RESOURCE_ATTRIBUTES=foo=bar,deployment.environment.name=stable,service.name=service,xyz=abc,service.version=1.2.3,baz=qux
--FILE--
<?php

var_dump(ini_get("datadog.env"));
var_dump(ini_get("datadog.service"));
var_dump(ini_get("datadog.version"));
var_dump(ini_get("datadog.tags"));

?>
--EXPECT--
string(6) "stable"
string(7) "service"
string(5) "1.2.3"
string(23) "foo:bar,xyz:abc,baz:qux"
