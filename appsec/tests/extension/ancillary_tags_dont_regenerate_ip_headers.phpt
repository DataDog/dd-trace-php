--TEST--
Test headers ip generation does not happen twice when meta has it already
--INI--
datadog.appsec.extra_headers=,mY-header,,my-other-header
--ENV--
HTTP_X_FORWARDED_FOR=7.7.7.7,10.0.0.1
HTTP_X_CLIENT_IP=7.7.7.7
HTTP_X_REAL_IP=7.7.7.8
HTTP_X_FORWARDED=for="foo"
HTTP_X_CLUSTER_CLIENT_IP=7.7.7.9
HTTP_FORWARDED_FOR=7.7.7.10,10.0.0.1
HTTP_FORWARDED=for="foo"
HTTP_TRUE_CLIENT_IP=7.7.7.11
HTTP_MY_HEADER=my header value
HTTP_MY_OTHER_HEADER=my other header value
REMOTE_ADDR=7.7.7.12
--FILE--
<?php

use function datadog\appsec\testing\add_all_ancillary_tags;
use function datadog\appsec\testing\add_basic_ancillary_tags;

$all = array(
    "_dd.multiple-ip-headers" => "headers already generated somewhere else"
);
add_all_ancillary_tags($all);
var_dump($all["_dd.multiple-ip-headers"]);

$basic = array(
 "_dd.multiple-ip-headers" => "headers already generated somewhere else"
);
add_basic_ancillary_tags($basic);
var_dump($basic["_dd.multiple-ip-headers"]);


?>
--EXPECTF--
string(40) "headers already generated somewhere else"
string(40) "headers already generated somewhere else"
