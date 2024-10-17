--TEST--
Check enablement status when enabled by config
--INI--
datadog.appsec.enabled=1
extension=ddtrace.so
--FILE--
<?php
include __DIR__ . '/inc/phpinfo.php';

var_dump(get_configuration_value("State managed by remote config"));
var_dump(get_configuration_value("Current state"));

--EXPECT--
string(2) "No"
string(7) "Enabled"