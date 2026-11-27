--TEST--
Downstream curl requests: no WAF call before the request starts or after it ends; a running transfer is dropped
--DESCRIPTION--
G9.8, G6.6, G10.17: transfers made before rinit make no WAF call (the
request has not started), whether run with curl_exec() or by a multi handle;
the hooks that see them later, in the request (info_read, remove, close),
make none either. A transfer
still running when the request ends (rshutdown) is dropped without any call,
neither its response call nor the data-less call that closes its subcontext,
and what the app does with it afterwards (finishing the exec loop, removing
the handle, closing the multi handle, a curl_exec() of the handle) makes none
either.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 10, 'ok'));

function handle($url)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    return $ch;
}

function run($mh)
{
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status == CURLM_OK);
}

// the calls made since the last time
function calls($label)
{
    global $helper;
    ob_start(); // get_commands() prints "timeout" when there is nothing
    $execs = ce_execs($helper);
    ob_end_clean();
    echo "$label: ", count($execs), " call(s)", $execs ? ' (' .
        count(array_filter($execs, function ($e) { return $e[1]; })) . ' last)' : '', "\n";
}

echo "-- before the request\n";
var_dump(strlen(curl_exec(handle("$base/json"))));
$early = handle("$base/json");
$mh0 = curl_multi_init();
curl_multi_add_handle($mh0, $early);
run($mh0);
var_dump(strlen(curl_multi_getcontent($early)));

rinit();
calls('after the request started');
// what the app does in the request with the transfer of before it
while (curl_multi_info_read($mh0) !== false) {
}
curl_multi_remove_handle($mh0, $early);
curl_multi_close($mh0);
calls('hooks seeing the transfer of before the request');

echo "-- a transfer observed\n";
var_dump(strlen(curl_exec(handle("$base/json"))));
calls('curl_exec');

echo "-- running at the end of the request\n";
$mh = curl_multi_init();
$slow = handle("$base/slow");
curl_multi_add_handle($mh, $slow);
curl_multi_exec($mh, $running);
echo "running: $running\n";
calls('the first exec (W1)');
var_dump(rshutdown());

echo "-- after the request\n";
run($mh);
var_dump(curl_multi_getcontent($slow));
curl_multi_remove_handle($mh, $slow);
curl_multi_close($mh);
$again = handle("$base/json");
var_dump(strlen(curl_exec($again)));
$mh2 = curl_multi_init();
curl_multi_add_handle($mh2, $again);
run($mh2);
curl_multi_close($mh2);
calls('everything after rshutdown');
?>
--EXPECT--
-- before the request
int(22)
int(22)
after the request started: 0 call(s)
hooks seeing the transfer of before the request: 0 call(s)
-- a transfer observed
int(22)
curl_exec: 2 call(s) (1 last)
-- running at the end of the request
running: 1
the first exec (W1): 1 call(s) (0 last)
bool(true)
-- after the request
string(10) "0123456789"
int(22)
everything after rshutdown: 0 call(s)
