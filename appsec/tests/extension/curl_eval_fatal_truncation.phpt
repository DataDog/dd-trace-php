--TEST--
Downstream curl requests: a fatal error in a WAF call does not leak its truncation flag
--DESCRIPTION--
G6.18: the truncation of downstream data (here the URL of the second hop,
cut at the serialiser's string limit) is not the request's. The flag is put
back after the call; when a fatal error (injected, as the memory limit would
raise it while the call's reply is handled) cuts the call short inside the
PREREQ callback, it is put back where the bailout is caught, and the
request's data_truncated flag (reported by request_shutdown) stays false.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the expected calls');
?>
--INI--
datadog.appsec.enabled=1
; PHP 8.5+: no stack trace with the fatal error
fatal_error_backtraces=0
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,curl_eval_fatal_after,curl_eval_data_truncated};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));
register_shutdown_function(function () use ($helper) {
    foreach ($helper->get_commands() as $c) {
        if ($c[0] === 'request_exec' && isset($c[1][0]['server.io.net.url']) &&
            strpos($c[1][0]['server.io.net.url'], '/json?') !== false) {
            echo "the URL of the second hop was sent cut\n";
        }
    }
    echo "data_truncated: ";
    var_dump(curl_eval_data_truncated());
});
rinit();

$target = "$base/json?" . str_repeat('a', 10000);
$ch = curl_init("$base/redirect-to?u=" . urlencode($target));
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// 1: W1 of the first hop, 2: its response call, 3: W1 of the second hop (from
// the PREREQ callback), whose reply is the point of the fatal error
curl_eval_fatal_after(3, true);
curl_exec($ch);
echo "not reached\n";
?>
--EXPECTF--
Fatal error: Injected fatal error in downstream request analysis in %s on line %d
the URL of the second hop was sent cut
data_truncated: bool(false)
