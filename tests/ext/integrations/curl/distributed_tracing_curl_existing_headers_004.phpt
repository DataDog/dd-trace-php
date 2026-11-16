--TEST--
Verify trace source tag is sent on asm event
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=curl_exec
HTTP_X_DATADOG_ORIGIN=phpt-test
--FILE--
<?php
include 'curl_helper.inc';

function tagsToArray($tags_str) {
    $tags = [];    
    foreach (explode(',', $tags_str) as $keyValue) {
        $tagExploded = explode('=', $keyValue);
        $tags[$tagExploded[0]] = $tagExploded[1];
    }
    return $tags;
}

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/headers';
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
]);


DDTrace\Testing\emit_asm_event();
$response = curl_exec($ch);
show_curl_error_on_fail($ch);
if (PHP_VERSION_ID < 80000) { curl_close($ch); }

include 'distributed_tracing.inc';
$headers = dt_decode_headers_from_httpbin($response);
$tags = tagsToArray($headers['x-datadog-tags']);

var_dump($tags['_dd.p.ts']);

?>
--EXPECTF--
string(2) "02"
