--TEST--
Verify configuration can be shared across dd extensions
--INI--
extension=ddtrace.so
--ENV--
DD_ENV=prod
DD_TRACE_CLIENT_IP_HEADER_DISABLED=true
--FILE--
<?php

use function datadog\appsec\testing\zai_config_get_value;
use function datadog\appsec\testing\zai_config_get_global_value;

// DDtrace configuration is always expected to be owned by DDTrace due to
// enforced module order on zend activate

$ddtrace_config = ini_get_all("ddtrace");
printf("DDTrace local configuration\n");
var_dump($ddtrace_config["datadog.service"]["local_value"]);
var_dump($ddtrace_config["datadog.env"]["local_value"]);

printf("\nDDAppSec local configuration\n");
var_dump(zai_config_get_value("DD_SERVICE"));
var_dump(zai_config_get_value("DD_ENV"));

printf("\nDDTrace global configuration\n");
var_dump($ddtrace_config["datadog.service"]["global_value"]);
var_dump($ddtrace_config["datadog.env"]["global_value"]);

printf("\nDDAppSec global configuration\n");
var_dump(zai_config_get_global_value("DD_SERVICE"));
var_dump(zai_config_get_global_value("DD_ENV"));

printf("\nSet new ini values\n");

ini_set("datadog.service", "something_else");
ini_set("datadog.env", "staging");

$ddtrace_config = ini_get_all("ddtrace");

printf("\nDDTrace local configuration\n");
var_dump($ddtrace_config["datadog.service"]["local_value"]);
var_dump($ddtrace_config["datadog.env"]["local_value"]);

printf("\nDDAppSec local configuration\n");
var_dump(zai_config_get_value("DD_SERVICE"));
var_dump(zai_config_get_value("DD_ENV"));

printf("\nDDTrace global configuration\n");
var_dump($ddtrace_config["datadog.service"]["global_value"]);
var_dump($ddtrace_config["datadog.env"]["global_value"]);

printf("\nDDAppSec global configuration\n");
var_dump(zai_config_get_global_value("DD_SERVICE"));
var_dump(zai_config_get_global_value("DD_ENV"));


?>
--EXPECTF--
DDTrace local configuration
string(12) "appsec_tests"
string(4) "prod"

DDAppSec local configuration
string(12) "appsec_tests"
string(4) "prod"

DDTrace global configuration
string(12) "appsec_tests"
string(4) "prod"

DDAppSec global configuration
string(12) "appsec_tests"
string(4) "prod"

Set new ini values

DDTrace local configuration
string(14) "something_else"
string(7) "staging"

DDAppSec local configuration
string(14) "something_else"
string(7) "staging"

DDTrace global configuration
string(12) "appsec_tests"
string(4) "prod"

DDAppSec global configuration
string(12) "appsec_tests"
string(4) "prod"
