--TEST--
Test \datadog\appsec\v2\track_user_login_success id should be string with appsec disabled
--INI--
extension=ddtrace.so
datadog.appsec.testing=0
--ENV--
DD_APPSEC_ENABLED=0
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');
\datadog\appsec\v2\track_user_login_success(
  "login",
  [
    "id" => 1234,
  ],
  [
    "metakey1" => "metavalue",
    "metakey2" => "metavalue02",
]);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(1) {
  ["runtime-id"]=>
  string(%s) %s
}