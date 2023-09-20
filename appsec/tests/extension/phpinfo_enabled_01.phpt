--TEST--
Check enablement status by default
--FILE--
<?php
include __DIR__ . '/inc/phpinfo.php';

var_dump(get_configuration_value("State managed by remote config"));
var_dump(get_configuration_value("Current state"));

--EXPECT--
string(3) "Yes"
string(14) "Not configured"