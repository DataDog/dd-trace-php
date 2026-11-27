--TEST--
Downstream curl requests: a DONE message taken by a nested hook is flushed by the next exec that finds nothing running
--DESCRIPTION--
G4.3 exception, G4.18: transfer A of the multi handle is done, its DONE
message not yet read, while B (whose CURLOPT_FILE stream the app has closed)
is still running. ext/curl warns about the stream inside curl_multi_exec(),
before libcurl runs, and the app's error handler calls curl_multi_info_read()
on that multi handle: entered inside an original curl function, the hook does
nothing (G9.9). It takes A's message, but A gets no response call there. The
exec that finally finds nothing running flushes A and B, once.
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
$f = fopen('php://temp', 'w+');
curl_setopt($b, CURLOPT_FILE, $f);
curl_multi_add_handle($mh, $a);
curl_multi_add_handle($mh, $b);
// until A is done and B is not
do {
    curl_multi_exec($mh, $running);
    usleep(20000);
    $done = curl_getinfo($a, CURLINFO_HTTP_CODE) == 200;
} while (!$done && $running);
echo "A done, B running: ", $done && $running ? "yes" : "no", "\n";
ob_start(); // get_commands() prints "timeout" when there is nothing new
$execs = ce_execs($helper);
ob_end_clean();
echo "calls so far: ", count($execs), " (", count(array_filter($execs, function ($e) { return $e[1]; })), " last)\n";

fclose($f);
$taken = $warning = null;
ob_start(); // B's body goes to the default output once its stream is gone
set_error_handler(function ($no, $msg) use ($mh, $a, &$taken, &$warning) {
    $taken = curl_multi_info_read($mh);
    $warning = $msg;
    return true;
});
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 1.0);
        ob_start();
        $new = ce_execs($helper);
        ob_end_clean();
        if ($new) { // no response call before nothing is running
            echo "calls while running: ", count($new), "\n";
        }
        $execs = array_merge($execs, $new);
    }
} while ($running && $status == CURLM_OK);
restore_error_handler();
$default_output = ob_get_clean();
echo "warning: ", preg_replace('/^curl_multi_exec\(\): /', '', (string)$warning), "\n";
echo "B's body went to the default output: ", json_encode($default_output), "\n";
echo "message taken by the nested hook: ",
    $taken && $taken['handle'] === $a ? "A done" : var_export($taken, true), "\n";
$execs = array_merge($execs, ce_execs($helper));
ce_print(array_slice($execs, 2));
curl_multi_remove_handle($mh, $a);
curl_multi_remove_handle($mh, $b);
var_dump(rshutdown());
ce_check_closed(array_merge($execs, ce_execs($helper)));
?>
--EXPECT--
A done, B running: yes
calls so far: 2 (0 last)
warning: CURLOPT_FILE resource has gone away, resetting to default
B's body went to the default output: "0123456789"
message taken by the nested hook: A done
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["10"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
bool(true)
2 subcontext(s), all closed
