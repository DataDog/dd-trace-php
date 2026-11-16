--TEST--
Test DD_ENV takes precedence over OpenTelemetry deployment environment attributes
--ENV--
DD_ENV=datadog
OTEL_RESOURCE_ATTRIBUTES=foo=bar,deployment.environment=legacy,deployment.environment.name=stable,baz=qux
--FILE--
<?php

var_dump(ini_get("datadog.env"));
var_dump(ini_get("datadog.tags"));

?>
--EXPECT--
string(7) "datadog"
string(15) "foo:bar,baz:qux"
