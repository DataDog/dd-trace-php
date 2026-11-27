--TEST--
Downstream curl requests: a fatal error in the response call
--DESCRIPTION--
The WAF call for the response is made from the curl_exec hook, once libcurl
has returned, never from one of libcurl's callbacks (G9.1): the app's
callbacks run first, with the whole response, and the fatal error that the
call raises (injected; e.g. the memory limit) is raised right there, at
curl_exec, without being caught and re-raised.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
; PHP 8.5+: no stack trace with the fatal error
fatal_error_backtraces=0
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,curl_eval_fatal_after};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(['ok'], false);
register_shutdown_function(function () use ($helper, $log) {
    echo "\n== WAF calls\n";
    ce_print(ce_execs($helper));
    @unlink($log);
});
rinit();

$ch = curl_init("$base/slow");
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
    static $once = false;
    if (!$once) {
        $once = true;
        echo "write callback before the response call\n";
    }
    return strlen($data);
});
echo "before\n";
// the presend call (W1) is the first; the response call the second
curl_eval_fatal_after(2);
curl_exec($ch);
echo "not reached\n";
?>
--EXPECTF--
before
write callback before the response call

Fatal error: Injected fatal error in downstream request analysis in %s on line %d

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
