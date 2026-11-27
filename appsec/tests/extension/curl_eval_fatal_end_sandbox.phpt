--TEST--
Downstream curl requests: a fatal error in the closing WAF call still ends the transfer
--DESCRIPTION--
The response call of the last hop is made when the transfer ends. The fatal
error it raises (injected) must not skip the release of the transfer: it is
caught, the end finishes, and the hook raises the fatal error once back
(G9.3). Here curl_exec() runs in a tracer hook, whose sandbox swallows the
fatal error. The handle must then no longer have our functions installed.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (!function_exists('DDTrace\install_hook')) die('skip DDTrace\install_hook needed');
?>
--INI--
datadog.appsec.enabled=1
; PHP 8.5+: no stack trace with the fatal error
fatal_error_backtraces=0
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_eval_fatal_after};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 10, 'ok'));
rinit();

$ch = curl_init("$base/echo");
function hooked() { echo "app function\n"; }
DDTrace\install_hook('hooked', function () use ($base, $ch) {
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => '{"a":1}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    // W1 is the first call, the closing response call the second
    curl_eval_fatal_after(2);
    curl_exec($ch);
    echo "not reached\n";
});
hooked();
echo "after the hook\n";
var_dump(datadog\appsec\testing\curl_downstream_dump($ch)['installed']);
rshutdown();
?>
--EXPECT--
app function
after the hook
bool(false)
