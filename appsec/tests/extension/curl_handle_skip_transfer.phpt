--TEST--
A transfer whose handle fails validation or is unverified is not observed, not evaluated and unchanged; the next one is
--DESCRIPTION--
The CURL * of the handle of one real curl_exec() is made to fail (the probe
refused: "unverified", the memory not being a libcurl easy handle: "invalid").
That transfer is not observed (reason unverified_handle / invalid_handle), the
helper sees no request_exec for it, and the response is what the server sent.
The transfer after it is observed. The failure is injected for one call only.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (\datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'] < 0x074b00) {
    die('skip libcurl < 7.75.0: the magic number is not checked');
}
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_handle_force_next,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 20, 'ok'));
var_dump(rinit());

function run($base, $force)
{
    $ch = curl_init("$base/json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($force) {
        curl_handle_force_next($force);
    }
    $r = curl_exec($ch);
    $d = curl_downstream_dump($ch);
    echo "$force: result: ", var_export($r, true), ", observed: ",
        var_export($d['observed'], true), ", reason: ",
        var_export($d['reason'], true), "\n";
}

run($base, 'unverified');
run($base, 'invalid');
// both skipped transfers made no WAF call so far; the next one is observed
run($base, '');
var_dump(rshutdown());
$execs = ce_execs($helper);
$subctxs = [];
foreach ($execs as list($id)) {
    $subctxs[$id] = true;
}
echo count($subctxs), " subcontext(s) with WAF calls\n";
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
unverified: result: '{"status":"ok","n":42}', observed: false, reason: 'unverified_handle'
invalid: result: '{"status":"ok","n":42}', observed: false, reason: 'invalid_handle'
: result: '{"status":"ok","n":42}', observed: true, reason: NULL
bool(true)
1 subcontext(s) with WAF calls
1 subcontext(s), all closed
