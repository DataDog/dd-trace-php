--TEST--
Downstream curl requests: urlencoded bodies are parsed without PHP's input limits or diagnostics
--DESCRIPTION--
x-www-form-urlencoded request bodies with more fields than max_input_vars
(1000), and with names nested deeper than max_input_nesting_level, are parsed
without any warning reaching the app's error handler (which here throws, as
frameworks do: curl_exec() would throw after the request was sent). All the
fields are evaluated (G7.11: no max_input_vars limit); names nested too deep
are dropped, as parse_str() does. A urlencoded response is not parsed at all
(G7.8) and so raises nothing either, from inside libcurl.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
display_errors=0
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 12, 'ok'));
var_dump(rinit());
set_error_handler(function ($no, $str) {
    echo "error handler: $str\n";
    throw new ErrorException($str, 0, $no);
});

$fields = http_build_query(array_fill(0, 1100, 'v')) .
    '&deep' . str_repeat('[a]', 70) . '=x&last=1';
foreach (['request' => "$base/len", 'response' => "$base/formq?$fields"] as $label => $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($label === 'request') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    }
    try {
        $r = curl_exec($ch);
        echo "$label: ", strlen($r), " bytes\n";
    } catch (\Throwable $e) {
        echo "$label: curl_exec threw ", get_class($e), "\n";
    }
}
restore_error_handler();
var_dump(error_get_last());
var_dump(rshutdown());

echo preg_replace('/\?.*/', '?...', file_get_contents($log));
@unlink($log);
foreach (ce_execs($helper) as list($id, $last, $data)) {
    foreach (['server.io.net.request.body', 'server.io.net.response.body'] as $k) {
        if (isset($data[$k]) && is_array($data[$k])) {
            $b = $data[$k];
            echo "$id $k: ", count($b), " fields, [0]=", $b[0], ", [1099]=", $b[1099],
                ", last=", $b['last'], ", deep: ", isset($b['deep']) ? 'yes' : 'no', "\n";
        }
    }
}
?>
--EXPECTF--
bool(true)
request: %d bytes
response: %d bytes
NULL
bool(true)
POST /len
GET /formq?...
curl-1-0 server.io.net.request.body: 1101 fields, [0]=v, [1099]=v, last=1, deep: no
