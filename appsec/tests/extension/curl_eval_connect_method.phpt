--TEST--
Downstream curl requests: a transfer whose own method is CONNECT is skipped
--DESCRIPTION--
A transfer whose own method is CURLOPT_CUSTOMREQUEST "CONNECT" is not
observed (skip reason connect_method, G3.9): no WAF call at all, and the
transfer behaves as without the extension. The server still receives it.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (!defined('CURLOPT_REQUEST_TARGET')) die('skip CURLOPT_REQUEST_TARGET needed');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 10, 'ok'));
var_dump(rinit());
$ch = curl_init($base);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'CONNECT',
    CURLOPT_REQUEST_TARGET => 'auth.example.test:80']);
var_dump(curl_exec($ch));
var_dump(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
cd_dump($ch);
var_dump(rshutdown());
echo count(ce_execs($helper)), " request_exec call(s)\n";
echo "== server log\n";
echo file_get_contents($log);
@unlink($log);
?>
--EXPECT--
bool(true)
string(29) "proxy authentication required"
int(407)
observed=no reason='connect_method' installed=no hops=0
bool(true)
0 request_exec call(s)
== server log
CONNECT auth.example.test:80
