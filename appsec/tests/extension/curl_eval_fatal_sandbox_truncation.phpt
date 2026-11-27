--TEST--
Downstream curl requests: a fatal error swallowed by a sandbox in a hook does not leave the truncation flag set
--DESCRIPTION--
G6.18: the truncation of downstream data is not the request's. The presend
call (W1) of a curl_exec() called from a tracer hook (DDTrace\install_hook)
has no zend_try of ours: a fatal error in it (injected, as the memory limit
would raise it while the reply is handled) longjmps out with the saved flag
not yet put back, and the tracer's sandbox swallows it (G9.3). The flag is
put back at the next curl hook, so the request's data_truncated flag stays
false.
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
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,curl_eval_fatal_after,curl_eval_data_truncated};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));
rinit();

function transfer($url, $fatal)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($fatal) {
        // W1 is the first call; its reply is the point of the fatal error
        curl_eval_fatal_after(1, true);
    }
    curl_exec($ch);
    echo "after curl_exec\n";
    cd_close($ch);
}
function hooked() { echo "app function\n"; }
DDTrace\install_hook('hooked', function () use ($base) {
    echo "in the hook\n";
    // a URL cut at the serialiser's string limit
    transfer("$base/json?" . str_repeat('a', 10000), true);
    echo "not reached\n";
});
hooked();
transfer("$base/json", false);
echo "data_truncated after the next transfer: ";
var_dump(curl_eval_data_truncated());
?>
--EXPECT--
in the hook
app function
after curl_exec
data_truncated after the next transfer: bool(false)
