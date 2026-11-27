--TEST--
Downstream curl requests: a redirect hop starts at its PREREQ call
--DESCRIPTION--
With CURLOPT_FOLLOWLOCATION, a 302 and the request it leads to are two hops
of the same transfer, each started by a PREREQ call (G5.12). The collector
holds one head, the current hop's, and only the last hop's body (G5.6, G5.7):
at the end of the transfer that is the 200 and its body; the 302's body
(which libcurl does not pass to the write function anyway) is not in it. The
302's head was handed to that hop's response call (curl_eval_redirect.phpt).
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
$ch = curl_init("$base/redirect");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
var_dump(curl_exec($ch));
cd_dump($ch);
?>
--EXPECT--
string(22) "{"status":"ok","n":42}"
observed=yes reason=NULL installed=no hops=2
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
