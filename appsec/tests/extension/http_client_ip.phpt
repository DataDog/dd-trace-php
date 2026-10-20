--TEST--
http.client_ip is generated when not already done so
--ENV--
HTTP_X_FORWARDED_FOR=7.7.7.7
--FILE--
<?php
use function datadog\appsec\testing\add_all_ancillary_tags;

$with_ip_generated = array(
'http.client_ip' => 'ip generated somewhere else'
);
add_all_ancillary_tags($with_ip_generated);

$without_ip_generated = array();
add_all_ancillary_tags($without_ip_generated);

var_dump($with_ip_generated['http.client_ip']);
var_dump($without_ip_generated['http.client_ip']);

?>
--EXPECTF--
string(27) "ip generated somewhere else"
string(7) "7.7.7.7"
