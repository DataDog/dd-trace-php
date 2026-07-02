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
// Do NOT include curl_helper.inc here: its stub CurlIntegration class shadows the
// real integration and prevents the curl_multi_exec parent span from being created.

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port . '/';

// Far exceed DD_TRACE_SPANS_LIMIT. Before the fix, curl_multi_exec opened one
// parent span per call via the user-facing start_span(), bypassing the limit;
// distributed-header injection then flagged each span NOT_DROPPABLE, so they
// accumulated 1:1 with iterations (the reported OOM). The limit must cap them.
$iterations = 60;
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

// Read the limit state before serializing (serialization clears the counters).
var_dump(dd_trace_tracer_is_limited());

$spans = dd_trace_serialize_closed_spans();
$multiCount = 0;
foreach ($spans as $span) {
    if (($span['name'] ?? '') === 'curl_multi_exec') {
        $multiCount++;
    }
}

// With the limit enforced the retained curl_multi_exec spans stay near the limit
// rather than scaling 1:1 with the iteration count.
echo ($multiCount < $iterations / 2 ? 'BOUNDED' : 'LEAK') . "\n";
?>
--EXPECT--
bool(true)
BOUNDED
