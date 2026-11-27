--TEST--
Downstream curl requests: a transfer started inside another transfer's callback is not observed
--DESCRIPTION--
The app's WRITEFUNCTION of transfer A starts transfer B with curl_exec().
Hooks entered inside libcurl do nothing (G4.3): B gets no WAF call (the
helper would block it), no tag and no block, and returns its response. A is
evaluated as usual, and no block is deferred or re-raised once it is back.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\rinit;
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
// before the server's: it must still be running
register_shutdown_function(function () {
    global $a, $b_result;
    echo "\n== shutdown\n";
    echo "B: ", var_export($b_result, true), "\n";
});
$base = cd_server_start($log);
// A: URL+method+request headers (W1); B would get the block
$helper = ce_helper(['ok', 'block'], false);
ce_print_at_shutdown($helper, $log);
rinit();

$a = curl_init("$base/big?n=200000");
$writes = 0;
curl_setopt($a, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($base, &$writes) {
    if ($writes++ === 0) {
        $b = curl_init("$base/json?attack=1");
        curl_setopt($b, CURLOPT_RETURNTRANSFER, true);
        global $b_result;
        $b_result = curl_exec($b);
    }
    return strlen($data);
});
$res = curl_exec($a);
echo "A: ", var_export($res, true), ", writes: ", $writes > 1 ? "several" : $writes, "\n";
?>
--EXPECTF--
A: true, writes: several

== shutdown
B: '{"status":"ok","n":42}'

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/big?n=200000"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["200000"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
== server log
GET /big?n=200000
GET /json?attack=1
