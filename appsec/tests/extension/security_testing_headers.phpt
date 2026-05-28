--TEST--
Security-testing headers are collected on the root span
--INI--
extension=ddtrace.so
--ENV--
HTTP_X_DATADOG_ENDPOINT_SCAN=endpoint-scan-uuid
HTTP_X_DATADOG_SECURITY_TEST=security-test-uuid
--FILE--
<?php

use function datadog\appsec\testing\add_all_ancillary_tags;
use function datadog\appsec\testing\add_basic_ancillary_tags;

$arr = [];
add_all_ancillary_tags($arr);
var_dump($arr['http.request.headers.x-datadog-endpoint-scan']);
var_dump($arr['http.request.headers.x-datadog-security-test']);

$arr = [];
add_basic_ancillary_tags($arr);
var_dump($arr['http.request.headers.x-datadog-endpoint-scan']);
var_dump($arr['http.request.headers.x-datadog-security-test']);

?>
--EXPECT--
string(18) "endpoint-scan-uuid"
string(18) "security-test-uuid"
string(18) "endpoint-scan-uuid"
string(18) "security-test-uuid"
