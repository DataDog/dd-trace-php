--TEST--
Downstream curl requests: a caught fatal error is not re-raised inside an app callback run by libcurl
--DESCRIPTION--
G9.2, G9.3: a bailout caught in one of our libcurl callbacks (here, as the
one caught in the PREREQ callback of another transfer of the same
curl_multi_exec() would be: armed from the PROGRESSFUNCTION of a transfer by
the testing function, the same state) is re-raised once libcurl has
returned to the hooked function, never from the VM interrupt or from a hook
entered from inside libcurl: a longjmp out of libcurl's frames leaves its
in_callback flag set, leaks the transfer's descriptors and makes later calls
on the multi handle fail. The PROGRESSFUNCTION (with a backward jump, a VM
interrupt check, and a nested curl_exec(), a hook) runs to its end; the fatal
error is raised when curl_multi_exec() returns.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif_testing.php';
if (!extension_loaded('curl')) die('skip curl needed');
?>
--INI--
datadog.appsec.enabled=1
; PHP 8.5+: no stack trace with the fatal error
fatal_error_backtraces=0
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,curl_eval_catch_fatal};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'), false);
$phase = 0;
register_shutdown_function(function () use (&$phase) {
    echo "\nprogress callback phase reached: $phase (3: completed)\n";
});
rinit();

$mh = curl_multi_init();
$a = curl_init("$base/json");
curl_setopt($a, CURLOPT_RETURNTRANSFER, true);
curl_setopt($a, CURLOPT_NOPROGRESS, false);
curl_setopt($a, CURLOPT_PROGRESSFUNCTION, function () use (&$phase, $base) {
    if ($phase > 0) {
        return 0;
    }
    $phase = 1;
    curl_eval_catch_fatal(); // as a caught bailout leaves the state
    for ($i = 0; $i < 3; $i++) { // a backward jump: a VM interrupt check
        $x = $i;
    }
    $phase = 2;
    $b = curl_init("$base/json"); // a nested hook
    curl_setopt($b, CURLOPT_RETURNTRANSFER, true);
    curl_exec($b);
    $phase = 3;
    return 0;
});
curl_multi_add_handle($mh, $a);
do {
    curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 0.1);
    }
} while ($running);
echo "not reached\n";
?>
--EXPECTF--
Fatal error: Injected fatal error in downstream request analysis in %s on line %d

progress callback phase reached: 3 (3: completed)
