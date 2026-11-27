--TEST--
Downstream curl requests: an interim 100 Continue is not the response status
--DESCRIPTION--
The status of the response call is that of the final non-1xx head. A POST with
"Expect: 100-continue" is answered by the test server with a 100 Continue
before the 200.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
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
$helper = ce_helper(array_fill(0, 3, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/echo");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => 'a=b',
    CURLOPT_HTTPHEADER => ['Expect: 100-continue'],
]);
var_dump(curl_exec($ch));
var_dump(rshutdown());
$execs = ce_execs($helper);
foreach ($execs as list($id, $last, $data)) {
    if ($last) {
        var_dump($data['server.io.net.response.status']);
    }
}
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
string(3) "a=b"
bool(true)
string(3) "200"
1 subcontext(s), all closed
