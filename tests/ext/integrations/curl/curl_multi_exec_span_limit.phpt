--TEST--
curl_multi_exec parent spans honor DD_TRACE_SPANS_LIMIT (APMS-19944)
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=1
DD_TRACE_SPANS_LIMIT=20
DD_TRACE_LOG_LEVEL=error
--FILE--
<?php
$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port . '/';

$iterations = 100;
for ($i = 0; $i < $iterations; $i++) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $ch);
    do {
        $status = curl_multi_exec($mh, $active);
        curl_multi_select($mh);
    } while ($active > 0 && $status === CURLM_OK);
    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
}

$spans = dd_trace_serialize_closed_spans();
$multiCount = 0;
foreach ($spans as $span) {
    if (($span['name'] ?? '') === 'curl_multi_exec') {
        $multiCount++;
    }
}

echo ($multiCount <= $iterations / 2 ? 'BOUNDED' : 'LEAK') . "\n";
?>
--EXPECT--
BOUNDED
