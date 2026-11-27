--TEST--
Downstream curl requests: option numbers passed as numeric strings or floats are tracked
--DESCRIPTION--
In weak mode, curl_setopt() accepts the option number as a numeric string,
a float or a bool, which its parameter parsing converts without changing the
argument. The analysis must convert it the same way: CURLOPT_URL set that
way is the URL evaluated (the one libcurl requests).
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
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 20, 'ok'));
var_dump(rinit());

$numeric = ['string' => (string)CURLOPT_URL, 'float' => (float)CURLOPT_URL,
    'spaces' => ' ' . CURLOPT_URL];
foreach ($numeric as $label => $option) {
    $ch = curl_init("$base/json?benign=1");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, $option, "$base/json?$label=1");
    curl_exec($ch);
}
var_dump(rshutdown());
echo "== WAF calls (URLs)\n";
foreach (ce_execs($helper) as list($id, $last, $data)) {
    if (isset($data['server.io.net.url'])) {
        echo "$id ", $data['server.io.net.url'], "\n";
    }
}
echo "== server log\n";
echo file_get_contents($log);
@unlink($log);
?>
--EXPECT--
bool(true)
bool(true)
== WAF calls (URLs)
curl-1-0 http://127.0.0.1:PORT/json?string=1
curl-2-0 http://127.0.0.1:PORT/json?float=1
curl-3-0 http://127.0.0.1:PORT/json?spaces=1
== server log
GET /json?string=1
GET /json?float=1
GET /json?spaces=1
