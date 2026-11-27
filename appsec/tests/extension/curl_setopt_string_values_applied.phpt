--TEST--
Downstream curl requests: a null or array value of a string option is recorded as ext/curl applies it
--DESCRIPTION--
ext/curl converts the value of CURLOPT_CUSTOMREQUEST and the other string
options with zval_get_string(): an array is "Array" and null resets the option
(the default method). The state records what
ext/curl applied, so the method the WAF sees is the one libcurl sends (G4.13:
verbatim, "" included; G4.10: no silent wrong record). The server log shows
what libcurl sent.
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
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 30, 'ok'), false);
var_dump(rinit());
$recorded = [];

$cases = [
    'pAtCh' => 'pAtCh',
    'empty' => '',
    'null' => null,
    'array' => ['x'],
];
foreach ($cases as $label => $value) {
    $ch = curl_init("$base/len");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    @curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $value);
    $recorded[$label] = curl_downstream_dump($ch)['options']['customrequest'];
    @curl_exec($ch);
}
$wire = [];
foreach (explode("\n", trim(file_get_contents($log))) as $line) {
    // the server splits "<method> <target>" at the first space: an empty
    // method leaves the target first
    $wire[] = strpos($line, '/len') === 0 ? '' : substr($line, 0, strrpos($line, ' '));
}
@unlink($log);
$waf = [];
foreach (ce_execs($helper) as list($id, $last, $data)) {
    if (isset($data['server.io.net.request.method'])) {
        $waf[] = $data['server.io.net.request.method'];
    }
}
$i = 0;
foreach ($cases as $label => $value) {
    $sent = $wire[$i];
    echo "$label: recorded ", var_export($recorded[$label], true), ", sent ",
        var_export($sent, true), ", WAF ", var_export($waf[$i], true), "\n";
    $i++;
}
?>
--EXPECT--
bool(true)
pAtCh: recorded 'pAtCh', sent 'pAtCh', WAF 'pAtCh'
empty: recorded '', sent '', WAF ''
null: recorded NULL, sent 'GET', WAF 'GET'
array: recorded 'Array', sent 'Array', WAF 'Array'
