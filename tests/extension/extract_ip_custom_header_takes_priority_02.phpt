--TEST--
Client ip header takes priority over any other ip header
--ENV--
HTTP_X_FORWARDED_FOR=7.7.7.7
HTTP_FOO_BAR=1.2.3.4
DD_TRACE_CLIENT_IP_HEADER=foo-Bar
--FILE--
<?php

use function datadog\appsec\testing\add_all_ancillary_tags;
use function datadog\appsec\testing\add_basic_ancillary_tags;

$arr = array();
add_all_ancillary_tags($arr);
var_dump($arr['http.client_ip']);
var_dump(isset($arr['_dd.multiple-ip-headers']));

$arr = array();
add_basic_ancillary_tags($arr);
var_dump($arr['http.client_ip']);
var_dump(isset($arr['_dd.multiple-ip-headers']));

?>
--EXPECTF--
string(7) "1.2.3.4"
bool(false)
string(7) "1.2.3.4"
bool(false)
