--TEST--
Downstream curl requests: a fiber suspended inside libcurl makes later hooks do nothing
--DESCRIPTION--
There is one depth counter, not one per fiber (G9.9): a fiber suspended from
a libcurl callback (here the PROGRESSFUNCTION of a transfer it runs with
curl_multi_exec()) leaves the thread "inside libcurl". The transfer the main
fiber runs after it is then a nested one: no WAF call (the helper would
block it), no tag and no block. The consequence is accepted: nested calls
are rare and are not observed.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID < 80100) die('skip PHP 8.1+ (fibers)');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\rinit;
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(['ok', 'block'], false);
register_shutdown_function(function () use (&$fiber) {
    echo "\nfiber suspended: ", var_export($fiber->isSuspended(), true), "\n";
});
ce_print_at_shutdown($helper, $log);
rinit();

$fiber = new Fiber(function () use ($base) {
    $mh = curl_multi_init();
    $ch = curl_init("$base/slow");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function () {
        Fiber::suspend('suspended in libcurl');
        return 0;
    });
    curl_multi_add_handle($mh, $ch);
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running);
});
$fiber->start();
$ch = curl_init("$base/json?attack=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
?>
--EXPECTF--
string(22) "{"status":"ok","n":42}"

fiber suspended: true

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
== server log%A
