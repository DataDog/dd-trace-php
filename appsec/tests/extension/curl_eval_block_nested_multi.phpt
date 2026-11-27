--TEST--
Downstream curl requests: nested multi hooks do not start, flush, close or block anything
--DESCRIPTION--
G4.3, G9.9: the WRITEFUNCTION of transfer A (curl_exec) calls, inside libcurl,
curl_multi_remove_handle() and curl_multi_close() on a multi handle holding a
handle that was added but not started (its W1 would get a block verdict), and
curl_multi_info_read(), curl_multi_remove_handle() and curl_multi_close() on a
multi handle with a transfer that is done and one still running. The hooks
entered there do nothing: no W1 for the pending handle, no response call and
no data-less close for the other two, no block. A completes normally and the
only WAF calls are the ones of the three transfers started and A's response.
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
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
// W1 of D, E and A, the response of A; any other call would be blocked
$helper = ce_helper(['ok', 'ok', 'ok', 'ok', 'block', 'block']);
rinit();

// m3: D is done, E is still running, nobody has read D's message
$m3 = curl_multi_init();
$d = curl_init("$base/json");
curl_setopt($d, CURLOPT_RETURNTRANSFER, true);
$e = curl_init("$base/slow");
curl_setopt($e, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($m3, $d);
curl_multi_add_handle($m3, $e);
do {
    curl_multi_exec($m3, $running);
    usleep(20000);
} while (curl_getinfo($d, CURLINFO_RESPONSE_CODE) != 200 && $running);
echo "D done, E running: ", curl_getinfo($d, CURLINFO_RESPONSE_CODE) == 200 && $running ? "yes" : "no", "\n";

// m2: P is added, not started: its W1 would block
$m2 = curl_multi_init();
$p = curl_init("$base/json?attack=1");
curl_setopt($p, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($m2, $p);

$writes = 0;
$a = curl_init("$base/big?n=200000");
curl_setopt($a, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$writes, $m2, $m3, $p, $e) {
    if ($writes++ === 0) {
        var_dump(curl_multi_remove_handle($m2, $p));
        curl_multi_close($m2);
        $msg = curl_multi_info_read($m3);
        echo "nested info_read: ", $msg ? "message" : "false", "\n";
        var_dump(curl_multi_remove_handle($m3, $e));
        curl_multi_close($m3);
    }
    return strlen($data);
});
var_dump(curl_exec($a));
echo "writes: ", $writes > 1 ? "several" : $writes, "\n";

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
?>
--EXPECT--
D done, E running: yes
int(0)
nested info_read: message
int(0)
bool(true)
writes: several
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-3-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/big?n=200000"}
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["200000"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
