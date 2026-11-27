--TEST--
Downstream curl requests: urlencoded bodies are split on '&' only
--DESCRIPTION--
Deliberate difference from parse_str(): the separator is always '&', whatever
arg_separator.input says (it is about the app's own input, not about what a
downstream server does). With arg_separator.input=";" parse_str() would split
'a=1;b=2&c=3' into a and "b=2&c=3"; the native parser gives a="1;b=2" and c.
NUL bytes in values are not a difference: both keep them.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
arg_separator.input=;
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 2, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/len");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'a=1;b=2&c=3');
curl_exec($ch);
parse_str('a=1;b=2&c=3', $parsed);
echo 'parse_str: ', json_encode($parsed), "\n";
var_dump(rshutdown());
foreach (ce_execs($helper) as list($id, $last, $data)) {
    if (!$last) {
        echo 'native: ', json_encode($data['server.io.net.request.body']), "\n";
    }
}
?>
--EXPECT--
bool(true)
parse_str: {"a":"1","b":"2&c=3"}
bool(true)
native: {"a":"1;b=2","c":"3"}
