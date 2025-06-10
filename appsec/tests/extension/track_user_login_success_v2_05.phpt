--TEST--
Test \datadog\appsec\v2\track_user_login_success id should be string
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
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
Warning: datadog\appsec\v2\track_user_login_success(): [ddappsec] Unexpected id type in datadog\appsec\v2\track_user_login_success in %s on line %d
array(1) {
  ["runtime-id"]=>
  string(%s) %s
}