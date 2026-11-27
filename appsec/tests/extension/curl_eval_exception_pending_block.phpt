--TEST--
Downstream curl requests: a block recorded with an exception pending still applies
--DESCRIPTION--
G9.12, G9.11: the app's header callback throws on the last line of the head of
the 302 (on an earlier line, PHP 8.1 would warn and abort the transfer at the
next call of the callback, as its zend_call_function() returns FAILURE with an
exception pending; 8.2 and later skip the call), and ext/curl leaves the
transfer running, so the PREREQ callback of hop 1 runs
with the exception pending. Its W2 runs with the exception saved and
restored and gets a blocking verdict: PREREQ aborts hop 1 (no byte of it is
sent), and the block recorded in the transfer applies at the safe point,
once curl_exec() is back from libcurl, by the usual block path: the request
is blocked and the code after curl_exec(), the app's catch block included,
is not reached. No WAF call is made while the exception is pending.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID < 80100) {
    die('skip the behaviour of the callbacks on PHP < 8.1 was not checked (G9.12)');
}
if (PHP_VERSION_ID >= 80600) {
    die('skip ext/curl aborts the transfer when a callback throws from PHP 8.6, so no WAF call is made with an exception pending');
}
curl_skipif_needs_prereq('the expected calls');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,curl_eval_pending_exception_calls};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
register_shutdown_function(function () {
    global $cd_server_base;
    file_get_contents("$cd_server_base/json?sync");
    echo "\ncalls made with the exception pending: ",
        curl_eval_pending_exception_calls(), "\n";
});
$base = cd_server_start($log);
$helper = ce_helper(['ok', 'ok', 'block'], false);
ce_print_at_shutdown($helper, $log);
rinit();
$ch = curl_init("$base/redirect");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) {
    static $thrown = false;
    if (!$thrown && trim($line) === '') {
        $thrown = true;
        throw new Exception('from the header callback');
    }
    return strlen($line);
});
try {
    curl_exec($ch);
    echo "not reached\n";
} catch (Exception $e) {
    echo "not reached: exception from curl_exec: ", $e->getMessage(), "\n";
}
echo "not reached\n";
?>
--EXPECTHEADERS--
Status: 403 Forbidden
Content-type: application/json
--EXPECTF--
{"errors":[{"title":"You've been blocked","detail":"Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}],"security_response_id":""}
Warning: curl_exec(): Datadog blocked the request and presented a static error page. No action required. Security Response ID:  in %s on line %d

calls made with the exception pending: 0

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["/json"]},"server.io.net.response.status":"302"}
curl-1-1 {"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
== server log
GET /redirect
(no request bytes)
GET /json?sync
