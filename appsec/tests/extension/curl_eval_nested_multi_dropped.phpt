--TEST--
Downstream curl requests: a transfer completing while a nested hook runs is not reported by it, and is dropped if no hook sees it again
--DESCRIPTION--
G4.3, exception 1: transfer A of the multi handle is done while the slower
transfer C is still running in it. The WRITEFUNCTION of an unrelated curl_exec()
(inside libcurl) calls curl_multi_info_read() on that multi handle, which takes
A's DONE message. The hook entered there does nothing: A gets no response call
and its subcontext is not closed. The app never calls another hook on that
multi handle, so the transfer is dropped at the end of the request without
any WAF call (G6.6): neither the response call nor the call without data.
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
$c = curl_init("$base/slow");
curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $a);
curl_multi_add_handle($mh, $c);
// until A is done and C is not (no hook of $mh after it reports A)
do {
    curl_multi_exec($mh, $running);
    usleep(20000);
    $done = curl_getinfo($a, CURLINFO_HTTP_CODE) == 200;
} while (!$done && $running);
echo "A done, C running: ", $done && $running ? "yes" : "no", "\n";

$nested = 0;
$b = curl_init("$base/len");
curl_setopt($b, CURLOPT_RETURNTRANSFER, true);
curl_setopt($b, CURLOPT_POSTFIELDS, 'x=1');
curl_setopt($b, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($mh, $a, $helper, &$nested) {
    if ($nested++ === 0) {
        $msg = curl_multi_info_read($mh); // A's message: not ours to report
        echo "nested info_read: ", $msg ? ($msg['handle'] === $a ? "A done" : "other") : "false", "\n";
        echo "A's status: ", curl_getinfo($a, CURLINFO_RESPONSE_CODE), "\n";
        echo "WAF calls at that point:\n";
        ce_print(ce_execs($helper));
    }
    return strlen($data);
});
curl_exec($b);
echo "nested hook ran: ", $nested > 0 ? "yes" : "no", "\n";

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
A done, C running: yes
nested info_read: A done
A's status: 200
WAF calls at that point:
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-3-0 {"server.io.net.request.headers":{"content-type":["application/x-www-form-urlencoded"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/len"}
nested hook ran: yes
bool(true)
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["16"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
1 subcontext(s), all closed
