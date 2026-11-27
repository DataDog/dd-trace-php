--TEST--
Downstream curl requests: a fatal error in the read-function gate's call aborts the transfer and is re-raised after libcurl returns
--DESCRIPTION--
G6.7, G9.2, G9.3: the request-stage call of the read-function gate runs
under zend_try inside libcurl's read callback. A fatal error there (injected
as the memory limit would raise it) is caught, the read function returns
CURL_READFUNC_ABORT, so no body byte is sent, and the fatal error is raised
again once curl_exec() is back from libcurl: the code after curl_exec() is
not reached, and libcurl's frames were not unwound by the longjmp.
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
--FILE--
<?php
use function datadog\appsec\testing\{rinit,curl_eval_fatal_after};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$new = datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'] >= 0x080700;
$reads = 0;
register_shutdown_function(function () use ($log, $new, &$reads) {
    global $cd_server_base;
    file_get_contents("$cd_server_base/json?sync");
    echo "\nread function called $reads time(s)\n";
    $got = (string)@file_get_contents($log);
    $expected = ($new ? "(no request bytes)\n" : "PUT /recv\nbody 0 bytes\n") .
        "GET /json?sync\n";
    echo 'server log: ', $got === $expected ? "as expected for this libcurl\n" : $got;
    @unlink($log);
});
// after the function above, so that the server is still running then
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 8, 'ok'), false);
rinit();

$pieces = ['{"a":"fatal"}'];
$ch = curl_init("$base/recv");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_UPLOAD, true);
curl_setopt($ch, CURLOPT_INFILESIZE, 13);
curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$pieces, &$reads) {
    $reads++;
    return (string)array_shift($pieces);
});
curl_eval_fatal_after(2); // W1, then the gate's call
curl_exec($ch);
echo "not reached\n";
?>
--EXPECTF--
Fatal error: Injected fatal error in downstream request analysis in %s on line %d

read function called 1 time(s)
server log: as expected for this libcurl
