--TEST--
Downstream curl requests: a fatal error caught inside libcurl is re-raised at the hook, and swallowed by a tracer sandbox there
--DESCRIPTION--
The WAF call for the response of the first hop of a redirect is made from
the PREREQ callback of the second hop, that is, inside libcurl, and raises
a fatal error (injected). It is caught there and re-raised by curl_exec()'s
hook once libcurl has returned (G9.3), and by nothing else: there is no VM
interrupt handler and no tracking of sandboxes. Here curl_exec() is called
from a tracer hook (DDTrace\install_hook), which the tracer runs in a
sandbox: the sandbox swallows the re-raised fatal error as it would any
other fatal error of a hook closure (the exception to G9.3), and the app's
code goes on.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (!function_exists('DDTrace\install_hook')) die('skip DDTrace\install_hook needed');
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
use function datadog\appsec\testing\{rinit,curl_eval_fatal_after};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(['ok'], false);
register_shutdown_function(function () use ($log) {
    echo "\nshutdown\n";
    @unlink($log);
});
rinit();

function transfer($base)
{
    $ch = curl_init("$base/redirect-to?u=" . urlencode("$base/slow"));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
        echo "write callback after the fatal error\n";
        return strlen($data);
    });
    // the presend call (W1) is the first; the response call of hop 0 the
    // second (made from the PREREQ callback of hop 1)
    curl_eval_fatal_after(2);
    curl_exec($ch);
    echo "not reached\n";
}
function hooked() { echo "app function\n"; }
DDTrace\install_hook('hooked', function () use ($base) {
    echo "in the hook\n";
    transfer($base);
    echo "not reached\n";
});
hooked();
echo "after the hook\n";
?>
--EXPECT--
in the hook
app function
after the hook

shutdown
