--TEST--
Downstream curl requests: curl_multi_exec returning OK with nothing running flushes the response calls
--DESCRIPTION--
G4.18: an app that only polls curl_multi_exec() (no curl_multi_info_read(),
no curl_multi_remove_handle()) still gets its transfers evaluated: the exec
that returns CURLM_OK with $still_running 0 makes the response call of each
transfer of that multi handle and closes its subcontext. While something is
still running, an exec flushes nothing. Nothing is flushed twice: a later
curl_multi_info_read(), curl_multi_remove_handle() and curl_multi_close()
make no further call for these transfers.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=2
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));
rinit();
$mh = curl_multi_init();
$ch1 = curl_init("$base/json");
$ch2 = curl_init("$base/slow");
foreach ([$ch2] as $ch) {
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_multi_add_handle($mh, $ch);
}
// the slow transfer cannot be over after the first exec (its body comes
// 300 ms after the head), so that exec flushes nothing; the fast one is
// added after it, to not depend on how much one perform gets done
$status = curl_multi_exec($mh, $running);
echo "first exec status: $status, running: ", $running > 0 ? "yes" : "no", "\n";
curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch1);
$status = curl_multi_exec($mh, $running);
echo "second exec status: $status, running: ", $running > 0 ? "yes" : "no", "\n";
// W1 of both transfers, nothing else: the slow one is still running
$execs = ce_execs($helper); // get_commands() returns each command once
$last_calls = array_filter($execs, function ($e) { return $e[1]; });
echo "calls after the first execs: ", count($execs), ", last calls: ",
    count($last_calls), "\n";
while ($running && $status == CURLM_OK) {
    curl_multi_select($mh, 1.0);
    $status = curl_multi_exec($mh, $running);
}
echo "exec status: $status, running: $running\n";
$execs = array_merge($execs, ce_execs($helper));
$groups = [];
foreach ($execs as $e) {
    $groups[$e[0]][] = $e;
}
ksort($groups);
echo "after the last exec:\n";
ce_print(array_merge(...array_values($groups)));

// no further call from the other hooks
while (curl_multi_info_read($mh) !== false) {
}
curl_multi_remove_handle($mh, $ch1);
curl_multi_remove_handle($mh, $ch2);
curl_multi_close($mh);
ob_start(); // get_commands() prints "timeout" when there is nothing
$more = ce_execs($helper);
ob_end_clean();
echo "calls after info_read, remove and close: ", count($more), "\n";

var_dump(rshutdown());
ce_check_closed(array_merge($execs, $more, ce_execs($helper)));
?>
--EXPECT--
first exec status: 0, running: yes
second exec status: 0, running: yes
calls after the first execs: 2, last calls: 0
exec status: 0, running: 0
after the last exec:
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["10"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-2-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
calls after info_read, remove and close: 0
bool(true)
2 subcontext(s), all closed
