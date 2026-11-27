--TEST--
Downstream curl requests: a blocking verdict for a redirect hop before it is sent
--DESCRIPTION--
The redirect hop is evaluated from CURLOPT_PREREQFUNCTION; a blocking verdict
makes it return CURL_PREREQFUNC_ABORT, so the second request is never sent,
and the request is blocked after curl_exec returns. The connection to the
redirect target exists already (it precedes PREREQ), but it gets no byte:
the server logs it as "(no request bytes)". The response call of hop 0 is
made before W2 (in the same PREREQ callback), so its tags are kept, and no
call follows the block: no response or closing call for hop 1, and no
data-less close of its subcontext.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
// without it, no hop after the first is evaluated (G6.11)
curl_skipif_needs_prereq('the expected calls');
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
// runs before the log is printed, before the server is stopped: the server
// serves one connection at a time, in order, so once this request is served
// the aborted connection was logged
register_shutdown_function(function () {
    global $cd_server_base;
    file_get_contents("$cd_server_base/json?sync");
});
$base = cd_server_start($log);
$helper = ce_helper(['ok', 'ok', 'block'], false);
ce_print_at_shutdown($helper, $log);
rinit();
$ch = curl_init("$base/redirect");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
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
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["/json"]},"server.io.net.response.status":"302"}
curl-1-1 {"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
== server log
GET /redirect
(no request bytes)
GET /json?sync
