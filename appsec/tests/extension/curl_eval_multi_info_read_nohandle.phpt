--TEST--
Downstream curl requests: curl_multi_info_read() tolerates a DONE message without a handle (PHP <= 8.1)
--DESCRIPTION--
G4.18: PHP up to 8.1 deletes a handle from ext/curl's list of the multi
handle's easy handles when curl_multi_remove_handle() is called even if
libcurl refuses the removal (here: from a callback, CURLM_RECURSIVE_API_CALL).
The later DONE message of that transfer then has no "handle" key. The hook
does not fail and flushes nothing for it; the transfer is flushed by the
curl_multi_exec() that finds nothing running, once.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
if (PHP_VERSION_ID >= 80200) die('skip PHP <= 8.1: later versions keep the handle of the message');
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
curl_setopt($b, CURLOPT_RETURNTRANSFER, true);
$refused = null;
$once = false;
curl_setopt($b, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($mh, $a, &$refused, &$once) {
    if (!$once) {
        $once = true;
        // A is done; libcurl refuses, ext/curl forgets the handle anyway
        $refused = curl_multi_remove_handle($mh, $a);
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
echo "removal from the callback refused: ", $refused === 8 ? "yes" : var_export($refused, true), "\n";
$execs = ce_execs($helper);
ce_print($execs);
$n = 0;
while (($msg = curl_multi_info_read($mh)) !== false) {
    $n++;
    echo "message ", $msg['msg'] === CURLMSG_DONE ? "DONE" : "other",
        ", handle key: ", isset($msg['handle']) ? "yes" : "no", "\n";
}
echo "messages: $n\n";
ob_start(); // nothing new: get_commands() prints "timeout"
$more = ce_execs($helper);
ob_end_clean();
echo "calls made by curl_multi_info_read: ", count($more), "\n";
curl_multi_remove_handle($mh, $a);
curl_multi_remove_handle($mh, $b);
var_dump(rshutdown());
ce_check_closed(array_merge($execs, $more));
?>
--EXPECT--
removal from the callback refused: yes
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["10"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
message DONE, handle key: no
message DONE, handle key: yes
messages: 2
calls made by curl_multi_info_read: 0
bool(true)
2 subcontext(s), all closed
