--TEST--
Downstream curl requests: after a block no further call is made for the multi transfer
--DESCRIPTION--
G9.7, G6.6: the redirect hop of a multi transfer gets a blocking verdict at
W2. The response call of hop 0 preceded it (its tags are kept) and nothing
follows: not the response call or the closing call of hop 1, nor anything
from curl_multi_remove_handle() and curl_multi_close() of the blocked
transfer, which the app's shutdown function still makes.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
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
// runs before the log is printed, before the server is stopped
register_shutdown_function(function () {
    global $cd_server_base, $mh, $ch;
    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
    file_get_contents("$cd_server_base/json?sync");
});
$base = cd_server_start($log);
$helper = ce_helper(['ok', 'ok', 'block', 'ok', 'ok'], false);
ce_print_at_shutdown($helper, $log);
rinit();
$mh = curl_multi_init();
$ch = curl_init("$base/redirect");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_multi_add_handle($mh, $ch);
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running && $status == CURLM_OK);
echo "not reached\n";
?>
--EXPECTHEADERS--
Status: 403 Forbidden
Content-type: application/json
--EXPECTF--
{"errors":[{"title":"You've been blocked","detail":"Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}],"security_response_id":""}
Warning: curl_multi_exec(): Datadog blocked the request and presented a static error page. No action required. Security Response ID:  in %s on line %d

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["/json"]},"server.io.net.response.status":"302"}
curl-1-1 {"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
== server log
GET /redirect
(no request bytes)
GET /json?sync
