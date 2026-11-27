--TEST--
Downstream curl requests are observed along with the tracer's header injection
--DESCRIPTION--
Our ext/curl handlers run before the tracer's replacements of the same
functions: with an active span, the tracer injects distributed tracing
headers (through ext/curl's curl_setopt, bypassing ours). The transfer is
still observed, and the server gets those headers. The request as sent is
not observed (G5.5): the server reports the header names it received.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif_response.php';
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();
\DDTrace\start_span();
$ch = curl_init("$base/reqheaders");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
var_dump(json_decode($res));
$d = datadog\appsec\testing\curl_downstream_dump($ch);
echo 'observed=', cd_yn($d['observed']), ' hops=', $d['hops'],
    ' body copy is what the app got: ', var_export($d['response']['body'] === $res, true), "\n";
\DDTrace\close_span();
?>
--EXPECT--
array(8) {
  [0]=>
  string(6) "accept"
  [1]=>
  string(4) "host"
  [2]=>
  string(11) "traceparent"
  [3]=>
  string(10) "tracestate"
  [4]=>
  string(19) "x-datadog-parent-id"
  [5]=>
  string(27) "x-datadog-sampling-priority"
  [6]=>
  string(14) "x-datadog-tags"
  [7]=>
  string(18) "x-datadog-trace-id"
}
observed=yes hops=1 body copy is what the app got: true
