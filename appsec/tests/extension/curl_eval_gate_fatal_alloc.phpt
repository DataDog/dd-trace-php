--TEST--
Downstream curl requests: a fatal error in the read-function gate's own allocation aborts the transfer and is re-raised after libcurl returns
--DESCRIPTION--
G6.7, G9.2, G9.3, G9.6: the read-function gate's buffers have no memory
guard, so their allocation can raise the memory limit's fatal error inside
libcurl's read callback. Here the fatal error is injected (as the memory
limit would raise it; a low memory_limit queues a delayed OOM instead on
recent PHP versions) on the gate's last allocation, that of the bytes of the
read that crosses the cap (64), which are held past the body sink. It is
caught: the gate releases what it holds and the read function returns
CURL_READFUNC_ABORT, so no body byte is sent and no request-stage call is
made, and the fatal error is raised again once curl_exec() is back from
libcurl: the code after curl_exec() is not reached.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
; PHP 8.5+: no stack trace with the fatal error
fatal_error_backtraces=0
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_APPSEC_MAX_BODY_BUFF_SIZE=64
--FILE--
<?php
use function datadog\appsec\testing\{rinit,curl_eval_gate_fatal_after_alloc};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$new = datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'] >= 0x080700;
$reads = 0;
$helper = null;
register_shutdown_function(function () use ($log, $new, &$reads, &$helper) {
    global $cd_server_base;
    file_get_contents("$cd_server_base/json?sync");
    echo "\nread function called $reads time(s)\n";
    $got = (string)@file_get_contents($log);
    $expected = ($new ? "(no request bytes)\n" : "PUT /recv\nbody 0 bytes\n") .
        "GET /json?sync\n";
    echo 'server log: ', $got === $expected ? "as expected for this libcurl\n" : $got;
    @unlink($log);
    $bodies = 0;
    foreach (ce_execs($helper) as list($id, $last, $data)) {
        $bodies += isset($data['server.io.net.request.body']);
    }
    echo "$bodies body call(s)\n";
});
// after the function above, so that the server is still running then
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 8, 'ok'), false);
rinit();

// 40 bytes, then 60: the second read crosses the cap
$body = '{"a":"' . str_repeat('x', 92) . '"}';
$pieces = [substr($body, 0, 40), substr($body, 40)];
$ch = curl_init("$base/recv");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_UPLOAD, true);
curl_setopt($ch, CURLOPT_INFILESIZE, strlen($body));
curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$pieces, &$reads) {
    $reads++;
    return (string)array_shift($pieces);
});
curl_eval_gate_fatal_after_alloc(2); // the body sink (of the cap at once), then the bytes past it
curl_exec($ch);
echo "not reached\n";
?>
--EXPECTF--
Fatal error: Injected fatal error in downstream request analysis in %s on line %d

read function called 2 time(s)
server log: as expected for this libcurl
0 body call(s)
