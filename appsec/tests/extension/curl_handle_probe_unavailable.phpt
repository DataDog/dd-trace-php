--TEST--
A CURL * that cannot be probed is unverified: that transfer is skipped, the next one is checked again
--DESCRIPTION--
The CURL * of a handle is checked through process_vm_readv(2). The probe
being refused (ENOSYS, EPERM: old kernels, seccomp) says nothing about
ext/curl or libcurl: the transfer is not observed, with a debug line naming
the errno, and nothing reaches the app (no PHP warning). The next transfer is
validated again. The probe being refused is injected.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test_curl_probe.log
datadog.appsec.log_level=debug
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_handle_inject,curl_string_buffer_address};
require __DIR__ . '/inc/curl_eval.php';
include __DIR__ . '/inc/logging.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 20, 'ok'));
var_dump(rinit());
truncate_log();
set_error_handler(function ($no, $str) {
    echo "error handler: $str\n";
    return true;
});

$magic = "\xad\xdb\xde\xc0" . str_repeat("\0", 12);
$i = curl_handle_inject(curl_string_buffer_address($magic), 8, false);
echo "injected: {$i['status']}, validated=", var_export($i['validated'], true), "\n";
echo preg_match_all('/\[debug\] Not observing a curl transfer: CURL handle pointer 0x[0-9a-f]+ cannot be validated: process_vm_readv failed: /', log_contents()), " skip line(s)\n";

$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$r = curl_exec($ch);
echo "next: result: ", var_export($r, true), ", observed: ",
    var_export(datadog\appsec\testing\curl_downstream_dump($ch)['observed'], true), "\n";
var_dump(error_get_last());
restore_error_handler();
var_dump(rshutdown());
?>
--EXPECT--
bool(true)
injected: unverified, validated=false
1 skip line(s)
next: result: '{"status":"ok","n":42}', observed: true
NULL
bool(true)
