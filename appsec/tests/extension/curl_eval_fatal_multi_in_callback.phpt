--TEST--
Downstream curl requests: an inner curl_multi_exec() inside an app callback is not observed, so it cannot raise a fatal
--DESCRIPTION--
G4.3: curl_multi_exec() hooked inside the WRITEFUNCTION of a curl_exec() runs
with libcurl on the stack, and does nothing: its transfers (here a redirect,
whose hop 1 would make the WAF call that raises a fatal error, injected as
the memory limit would) get no WAF call at all. The fatal error armed for the
2nd call counted from there is not raised, the inner exec returns, the
callback runs to its end and so does curl_exec().
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
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'), false);
$phase = 0;
register_shutdown_function(function () use (&$phase) {
    echo "\ncallback phase reached: $phase (2: completed)\n";
});
rinit();

$outer = curl_init("$base/json");
curl_setopt($outer, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$phase, $base) {
    if ($phase == 0) {
        $phase = 1;
        $mh = curl_multi_init();
        $inner = curl_init("$base/redirect-to?u=" . urlencode("$base/json"));
        curl_setopt($inner, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($inner, CURLOPT_RETURNTRANSFER, true);
        curl_multi_add_handle($mh, $inner);
        // the 2nd WAF call from here: the one the redirect hop would make
        curl_eval_fatal_after(2);
        do {
            curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.1);
            }
        } while ($running);
        $phase = 2;
    }
    return strlen($data);
});
curl_exec($outer);
echo "outer transfer done\n";
?>
--EXPECT--
outer transfer done

callback phase reached: 2 (2: completed)
