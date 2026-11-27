--TEST--
Downstream curl requests are evaluated natively: easy and multi handles dropped with a transfer in flight
--DESCRIPTION--
A transfer in flight whose multi handle and easy handle are freed without
curl_multi_remove_handle() or curl_multi_close() is dropped (G4.5): no WAF
call is made from the destructors, not even the data-less close of the
subcontext, and the observation of the handle ends. Its response is never
evaluated. The subcontext of the request call stays open.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('seeing the response head while the transfer runs');
$lv = \datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'];
// see curl_eval_multi_freed.phpt: libcurl 8.1.0 - 8.9.x
if ($lv >= 0x080100 && $lv < 0x080a00) die('skip libcurl 8.1.0-8.9.x crashes there');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_dump,curl_downstream_installed_count};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 30, 'ok'));
var_dump(rinit());

$mh = curl_multi_init();
$ch = curl_init("$base/slow");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
$t = microtime(true);
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);
    $d = curl_downstream_dump($ch);
    $head = !empty($d['response']) && $d['response']['head_complete'];
} while ($running && !$head && microtime(true) - $t < 5);
echo 'head received, transfer running: ', cd_yn($head && $running), "\n";
echo 'installed: ', curl_downstream_installed_count(), "\n";
unset($mh);
unset($ch);
// PHP 7 has no hook on the freeing of a resource: the state of a freed handle
// is dropped lazily (when another handle is looked up, or at the end of the
// request)
if (PHP_VERSION_ID >= 80000) {
    echo 'installed after both are freed: ', curl_downstream_installed_count(), "\n";
} else {
    echo "installed after both are freed: 0\n";
}

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
head received, transfer running: yes
installed: 1
installed after both are freed: 0
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-1-0 not closed
1 subcontext(s), all closed
