--TEST--
Test OpenTelemetry config remapping
--SKIPIF--
<?php if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support custom INIs"); ?>
--ENV--
OTEL_SERVICE_NAME=service
OTEL_RESOURCE_ATTRIBUTES=foo=bar,deployment.environment=env,service.name=ignored,xyz=abc,service.version=1.2.3,baz=qux
OTEL_TRACES_EXPORTER=none
OTEL_METRICS_EXPORTER=none
--INI--
OTEL_SERVICE_NAME=other service
OTEL_LOG_LEVEL=warn
OTEL_PROPAGATORS[0]=datadog
OTEL_PROPAGATORS[1]=b3
OTEL_TRACES_SAMPLER=traceidratio
OTEL_TRACES_SAMPLER_ARG=0.5
--FILE--
<?php

var_dump(ini_get("datadog.env"));
var_dump(ini_get("datadog.service"));
var_dump(ini_get("datadog.version"));
var_dump(ini_get("datadog.tags"));
var_dump(ini_get("datadog.trace.enabled"));
var_dump(ini_get("datadog.trace.sample_rate"));
var_dump(ini_get("datadog.trace.propagation_style"));
var_dump(ini_get("datadog.trace.log_level"));
var_dump(ini_get("datadog.integration_metrics_enabled"));

?>
--EXPECT--
string(3) "env"
string(7) "service"
string(5) "1.2.3"
string(23) "foo:bar,xyz:abc,baz:qux"
string(1) "0"
string(3) "0.5"
string(24) "datadog,b3 single header"
string(4) "warn"
string(1) "0"
