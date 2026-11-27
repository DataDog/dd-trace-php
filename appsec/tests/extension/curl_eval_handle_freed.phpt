--TEST--
Downstream curl requests: freeing a curl handle flushes nothing
--DESCRIPTION--
G9.10, G4.5: a transfer of a multi handle that finished, was not removed and
has no response call yet because an exec still found C running. Freeing the handles, the multi handle
and handles that never ran only releases memory: no WAF call is made from
the destructors, and none follows later. Their subcontexts stay open (the
helper closes them when the request ends).
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
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_installed_count};
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
do {
    curl_multi_exec($mh, $running);
    usleep(20000);
} while (curl_getinfo($a, CURLINFO_HTTP_CODE) != 200 && $running);
echo "A done, C running: ", $running ? "yes" : "no", "\n";

// a pending handle, never started
$pend = curl_init("$base/json");
$mh_p = curl_multi_init();
curl_multi_add_handle($mh_p, $pend);

// an easy handle that ran in full, one that did not run
$e = curl_init("$base/json");
curl_setopt($e, CURLOPT_RETURNTRANSFER, true);
curl_exec($e);
$never = curl_init("$base/json");

ob_start(); // get_commands() prints "timeout" when there is nothing
$before = ce_execs($helper);
ob_end_clean();
echo "installed: ", curl_downstream_installed_count(), "\n";
unset($a, $c, $pend, $mh_p, $e, $never, $mh);
ob_start();
$after = ce_execs($helper);
ob_end_clean();
echo "calls made by the frees: ", count($after), "\n";

var_dump(rshutdown());
ce_print(array_merge($before, $after));
ce_check_closed(array_merge($before, $after, ce_execs($helper)));
?>
--EXPECT--
A done, C running: yes
installed: 2
calls made by the frees: 0
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-3-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-1-0 not closed
curl-2-0 not closed
3 subcontext(s), all closed
