--TEST--
Downstream curl requests: WAF calls made with an exception pending by a header callback
--DESCRIPTION--
G9.12: when the app's header callback throws on the first line of the 302's
head, ext/curl leaves the transfer running (the callback reports the line as
consumed), so the PREREQ callback of hop 1 runs with the exception pending
and the W2 of hop 0 and the call of hop 1 are made with the exception saved
and restored: none is made while it is pending. PHP 8.2 to 8.5 only: from 8.2
zend_call_function() returns SUCCESS when an exception is pending, so the
transfer goes on while the callback is skipped. PHP 8.1 returns FAILURE, and
ext/curl then warns and aborts the transfer, see the _81 variant of this test.
From PHP 8.6 ext/curl aborts the transfer as soon as a callback throws, see the
_86 variant.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID < 80200) {
    die('skip the behaviour of the callbacks on PHP < 8.2 differs: see curl_eval_exception_pending_header_81.phpt (G9.12)');
}
if (PHP_VERSION_ID >= 80600) {
    die('skip ext/curl aborts the transfer when a callback throws from PHP 8.6: see curl_eval_exception_pending_header_86.phpt (G9.12)');
}
curl_skipif_needs_prereq('the expected calls');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_eval_pending_exception_calls};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 16, 'ok'));
var_dump(rinit());
// the app's header callback throws on the first line of the 302's head
$ch = curl_init("$base/redirect");
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) {
    static $thrown = false;
    if (!$thrown) {
        $thrown = true;
        throw new Exception('from the header callback on ' . trim($line));
    }
    return strlen($line);
});
try {
    curl_exec($ch);
    echo "no exception\n";
} catch (Exception $e) {
    echo "exception from curl_exec: ", $e->getMessage(), "\n";
}

echo "calls made with the exception pending: ",
    curl_eval_pending_exception_calls(), "\n";
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECTF--
bool(true)
exception from curl_exec: from the header callback on HTTP/1.1 302 Found
calls made with the exception pending: 0
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["/json"]},"server.io.net.response.status":"302"}
curl-1-1 {"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-1 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
