--TEST--
Downstream curl requests: a transfer completing while a nested hook runs is not reported by it, but by the next hook that sees it
--DESCRIPTION--
G4.3, exception 1: transfer A of the multi handle is done while the slower
transfer B is still running. B's WRITEFUNCTION (inside libcurl) calls
curl_multi_info_read() and curl_multi_exec() on that multi handle (libcurl
refuses both while a callback runs). The hooks entered there do nothing: A
gets no response call, and its subcontext is not closed at that point. The
transfer is kept, and the
curl_multi_exec() that finds no transfer running flushes A (and B) once (G4.18).
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
$helper = ce_helper(array_fill(0, 10, 'ok'));
rinit();

$mh = curl_multi_init();
$a = curl_init("$base/json");
curl_setopt($a, CURLOPT_RETURNTRANSFER, true);
$b = curl_init("$base/slow");
$nested = 0;
curl_setopt($b, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($mh, $a, $helper, &$nested) {
    if ($nested++ === 0) {
        $msg = curl_multi_info_read($mh); // A's message: not ours to report
        $rc = curl_multi_exec($mh, $r); // nor does this one end anything
        echo "nested exec refused: ", $rc !== CURLM_OK ? "yes" : "no", "\n";
        echo "nested info_read: ", $msg ? ($msg['handle'] === $a ? "A done" : "other") : "false", "\n";
        echo "A's status: ", curl_getinfo($a, CURLINFO_RESPONSE_CODE), "\n";
        echo "WAF calls at that point:\n";
        ce_print(ce_execs($helper));
    }
    return strlen($data);
});
curl_multi_add_handle($mh, $a);
curl_multi_add_handle($mh, $b);
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running && $status == CURLM_OK);
echo "nested hook ran: ", $nested > 0 ? "yes" : "no", "\n";
curl_multi_remove_handle($mh, $a);
curl_multi_remove_handle($mh, $b);

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
nested exec refused: yes
nested info_read: false
A's status: 200
WAF calls at that point:
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
nested hook ran: yes
bool(true)
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["10"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
