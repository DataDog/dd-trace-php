--TEST--
Check enablement status when disabled by config
--INI--
datadog.appsec.enabled=0
extension=ddtrace.so
--FILE--
<?php
include __DIR__ . '/inc/phpinfo.php';

var_dump(get_configuration_value("State managed by remote config"));
var_dump(get_configuration_value("Current state"));

--EXPECT--
string(2) "No"
string(8) "Disabled"