--TEST--
Downstream curl requests: a block at W1 of a pending multi handle blocks the request
--DESCRIPTION--
G4.17: the first curl_multi_exec() makes W1 of a transfer added to a multi
handle. When it blocks, curl_multi_exec() returns CURLM_ABORTED_BY_CALLBACK
to the app's request, which is blocked: no connection is made and no further
call (W2, response or closing) follows, not even from
curl_multi_remove_handle() and curl_multi_close() called by the app's
shutdown function.
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
// runs before the log is printed, before the server is stopped
register_shutdown_function(function () {
    global $cd_server_base, $mh, $ch;
    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
    file_get_contents("$cd_server_base/json?sync");
});
$base = cd_server_start($log);
$helper = ce_helper(['block', 'ok', 'ok'], false);
ce_print_at_shutdown($helper, $log);
rinit();
$mh = curl_multi_init();
$ch = curl_init("$base/json?attack=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
curl_multi_exec($mh, $running);
echo "not reached\n";
?>
--EXPECTHEADERS--
Status: 403 Forbidden
Content-type: application/json
--EXPECTF--
{"errors":[{"title":"You've been blocked","detail":"Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}],"security_response_id":""}
Warning: curl_multi_exec(): Datadog blocked the request and presented a static error page. No action required. Security Response ID:  in %s on line %d

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json?attack=1"}
== server log
GET /json?sync
