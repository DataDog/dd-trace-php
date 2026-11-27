--TEST--
Downstream curl requests: WAF calls made with an exception pending by a header callback
--DESCRIPTION--
G9.12 on PHP 8.6, where ext/curl aborts the transfer as soon as a PHP callback
throws (the callbacks report a failure when the call's return value is undef),
instead of reporting the line as consumed as 8.2 to 8.5 do (see
curl_eval_exception_pending_header.phpt). The app's header
callback throws on the first line of the 302's head: the transfer is aborted
there, never reaches hop 1 and no further callback runs with the exception
pending, so no WAF call can be made with one. The exception still comes out of
curl_exec(), and the closing call of hop 0 is made (with no response data, as
the head was never completed) and its subcontext closed.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID < 80600) {
    die('skip PHP 8.6 and later only: see curl_eval_exception_pending_header.phpt and curl_eval_exception_pending_header_81.phpt for older versions (G9.12)');
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
curl-1-0 last []
1 subcontext(s), all closed
