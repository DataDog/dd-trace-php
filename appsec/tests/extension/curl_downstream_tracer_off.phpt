--TEST--
Downstream curl requests are observed with the tracer disabled
--DESCRIPTION--
Our ext/curl handlers chain to the tracer's replacements of the same
functions, which then do nothing (no distributed tracing headers: the server
reports the header names it received). The transfer is observed.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif_response.php';
?>
--ENV--
DD_TRACE_ENABLED=0
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();
$ch = curl_init("$base/reqheaders");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
var_dump(json_decode($res));
$d = datadog\appsec\testing\curl_downstream_dump($ch);
echo 'observed=', cd_yn($d['observed']), ' hops=', $d['hops'],
    ' body copy is what the app got: ', var_export($d['response']['body'] === $res, true), "\n";
?>
--EXPECT--
array(2) {
  [0]=>
  string(6) "accept"
  [1]=>
  string(4) "host"
}
observed=yes hops=1 body copy is what the app got: true
