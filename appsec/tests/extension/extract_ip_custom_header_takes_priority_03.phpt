--TEST--
When custom client ip header not present it does not fallback to extraction algorithm
--ENV--
HTTP_X_FORWARDED_FOR=7.7.7.7
DD_TRACE_CLIENT_IP_HEADER=foo-Bar
--FILE--
<?php

use function datadog\appsec\testing\add_all_ancillary_tags;
use function datadog\appsec\testing\add_basic_ancillary_tags;

$arr = array();
add_all_ancillary_tags($arr);
var_dump(isset($arr['http.client_ip']));
var_dump(isset($arr['_dd.multiple-ip-headers']));

$arr = array();
add_basic_ancillary_tags($arr);
var_dump(isset($arr['http.client_ip']));
var_dump(isset($arr['_dd.multiple-ip-headers']));

?>
--EXPECTF--
bool(false)
bool(false)
bool(false)
bool(false)
