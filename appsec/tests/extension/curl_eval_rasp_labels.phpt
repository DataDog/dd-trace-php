--TEST--
Downstream curl WAF calls are RASP evaluations: rasp_rule=ssrf, request or response
--DESCRIPTION--
The request-stage call (W1: URL, method, request headers) carries
rule_variant=request, the response-stage call (head and body together, one per
hop) rule_variant=response. The mock helper records the options of every
request_exec.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
var_dump(rshutdown());
$resp = 0;
foreach ($helper->get_commands() as $c) {
    if ($c[0] !== 'request_exec') {
        continue;
    }
    $o = $c[1][1];
    $keys = array_keys($c[1][0]);
    sort($keys);
    echo implode(' ', $keys), ' => ',
        $o['rasp_rule'] ?? '-', ' ', $o['rule_variant'] ?? '-', "\n";
    $resp += ($o['rule_variant'] ?? '') === 'response';
}
echo "response calls: $resp\n";
?>
--EXPECT--
bool(true)
string(22) "{"status":"ok","n":42}"
bool(true)
server.io.net.request.headers server.io.net.request.method server.io.net.url => ssrf request
server.io.net.response.body server.io.net.response.headers server.io.net.response.status => ssrf response
response calls: 1
