--TEST--
Downstream curl requests: WAF calls made with an exception pending
--DESCRIPTION--
G9.12: ext/curl leaves the transfer running when the app's write callback
throws, so the transfer reaches the next redirect hop (the W2 of the hop that
ended and the call of the next one are made from the PREREQ callback) and the
final flush with the exception pending. Every WAF call then runs with the
exception saved and restored: none is made while it is pending, the calls of
the second hop are made, and the exception still comes out of curl_exec().
The same holds for the read-function gate when the app's read callback throws
after a first piece (ext/curl takes the throw as the end of the body: the
gate's call is made with the piece it holds). The header callback has its own
tests, as zend_call_function() differs by PHP version.
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
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_eval_pending_exception_calls};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 16, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/redirect");
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
    static $thrown = false;
    if (!$thrown) {
        $thrown = true;
        throw new Exception('from the write callback');
    }
    return strlen($data);
});
try {
    curl_exec($ch);
    echo "no exception\n";
} catch (Exception $e) {
    echo "exception from curl_exec: ", $e->getMessage(), "\n";
}

// the app's read callback throws after a first piece of a gated body
$ch = curl_init("$base/recv");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_UPLOAD, true);
curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) {
    static $reads = 0;
    if ($reads++ == 0) {
        return '{"a":"piece"}';
    }
    throw new Exception('from the read callback');
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
exception from curl_exec: from the write callback
exception from curl_exec: from the read callback
calls made with the exception pending: 0
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["/json"]},"server.io.net.response.status":"302"}
curl-1-1 {"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-1 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/recv"}
curl-2-0 {"server.io.net.request.body":{"a":"piece"}}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["17"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
3 subcontext(s), all closed
