--TEST--
Downstream curl requests: a blocking verdict before the request is sent
--DESCRIPTION--
The first WAF call of the transfer, made with the data from curl_setopt
before calling ext/curl's curl_exec, blocks: the request is never sent (the
server logs nothing) and the app's request is aborted with the blocking
response.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\rinit;
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(['block'], false);
ce_print_at_shutdown($helper, $log);
rinit();
$ch = curl_init("$base/json?attack=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
echo "not reached\n";
?>
--EXPECTHEADERS--
Status: 403 Forbidden
Content-type: application/json
--EXPECTF--
{"errors":[{"title":"You've been blocked","detail":"Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}],"security_response_id":""}
Warning: curl_exec(): Datadog blocked the request and presented a static error page. No action required. Security Response ID:  in %s on line %d

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json?attack=1"}
== server log
