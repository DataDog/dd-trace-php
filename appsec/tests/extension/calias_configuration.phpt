--TEST--
Verify alias configuration can be shared across dd extensions
--INI--
extension=ddtrace.so
--ENV--
DD_SERVICE_NAME=appsec_tests
--FILE--
<?php

use function datadog\appsec\testing\zai_config_get_value;
use function datadog\appsec\testing\zai_config_get_global_value;

// DDtrace configuration is always expected to be owned by DDTrace due to
// enforced module order on zend activate

$ddtrace_config = ini_get_all("ddtrace");
printf("DDTrace local configuration\n");
var_dump($ddtrace_config["datadog.service"]["local_value"]);

printf("\nDDAppSec local configuration\n");
var_dump(zai_config_get_value("DD_SERVICE"));

printf("\nDDTrace global configuration\n");
var_dump($ddtrace_config["datadog.service"]["global_value"]);

printf("\nDDAppSec global configuration\n");
var_dump(zai_config_get_global_value("DD_SERVICE"));

?>
--EXPECTF--
DDTrace local configuration
string(12) "appsec_tests"

DDAppSec local configuration
string(12) "appsec_tests"

DDTrace global configuration
string(12) "appsec_tests"

DDAppSec global configuration
string(12) "appsec_tests"
