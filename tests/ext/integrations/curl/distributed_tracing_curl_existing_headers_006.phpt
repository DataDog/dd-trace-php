--TEST--
When APM tracing is disabled, if not asm event, no tags are sent
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=curl_exec
HTTP_X_DATADOG_ORIGIN=phpt-test
DD_APM_TRACING_ENABLED=0
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


$response = curl_exec($ch);
show_curl_error_on_fail($ch);
curl_close($ch);

include 'distributed_tracing.inc';
$headers = dt_decode_headers_from_httpbin($response);
var_dump(isset($headers['x-datadog-tags']));

?>
--EXPECTF--
bool(false)