--TEST--
Downstream curl requests: only the bodies the WAF calls need are kept, within the memory limit
--DESCRIPTION--
Bodies that no WAF call will send (here: not sampled) are not kept while
they are received, and nothing is kept once the transfer is over.
Transfers that are not evaluated (not http(s)) are not observed at all.
What is kept is allocated with emalloc, so it counts towards
memory_limit (shown with curl_downstream_collect_all(), which keeps
everything, for curl_downstream_dump()).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('keeping the response body');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 20, 'ok'), false);
rinit();

function resp_body_len($ch)
{
    $d = curl_downstream_dump($ch);
    // null: nothing is copied (not sampled)
    return $d && $d['response'] ? strlen((string)$d['response']['body']) : -1;
}

echo "== not sampled\n";
$ch = curl_init("$base/big?n=300000");
$max = 0;
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($h, $data) use (&$max) {
    $max = max($max, resp_body_len($h));
    return strlen($data);
});
curl_exec($ch);
echo "kept while received: $max, after: ", resp_body_len($ch), "\n";

echo "== not http\n";
// (whether libcurl supports file:// or not)
$ch = curl_init('file://' . __FILE__);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
$d = curl_downstream_dump($ch);
echo 'observed=', cd_yn($d['observed']), ' reason=', var_export($d['reason'], true), "\n";

echo "== kept bodies count towards the memory limit\n";
if (function_exists('datadog\appsec\testing\curl_downstream_collect_all')) {
    datadog\appsec\testing\curl_downstream_collect_all(true);
}
$ch = curl_init("$base/big?n=300000");
$devnull = fopen('/dev/null', 'w');
curl_setopt($ch, CURLOPT_FILE, $devnull);
$before = memory_get_usage();
curl_exec($ch);
echo "kept: ", resp_body_len($ch), "\n";
// (without ZendMM, e.g. under ASan, memory_get_usage() is always 0)
echo "in memory_get_usage(): ",
    cd_yn(memory_get_usage() === 0 || memory_get_usage() - $before >= 300000), "\n";
?>
--EXPECT--
== not sampled
kept while received: 0, after: 0
== not http
observed=no reason='scheme'
== kept bodies count towards the memory limit
kept: 300000
in memory_get_usage(): yes
