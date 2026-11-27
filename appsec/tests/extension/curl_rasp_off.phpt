--TEST--
Downstream curl requests: with DD_APPSEC_RASP_ENABLED=false no hook is installed and no WAF call is made
--DESCRIPTION--
The switch is read at startup. With RASP off the extension installs no curl
hook: a handle has no tracked state (curl_downstream_dump() finds none, which
the curl_init hook would have created) and the transfer, made inside a
request, gets no WAF call.
--SKIPIF--
<?php
// the layer is available but, with RASP off, not installed
$curl_skipif_hooks_optional = true;
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_APPSEC_RASP_ENABLED=false
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
var_dump(datadog\appsec\testing\curl_linkage_info()['layer_enabled']);
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
cd_dump($ch, 'handle');
var_dump(rshutdown());
$execs = ce_execs($helper);
echo count($execs), " request_exec call(s)\n";
?>
--EXPECT--
bool(false)
bool(true)
string(22) "{"status":"ok","n":42}"
== handle
not tracked
bool(true)
0 request_exec call(s)
