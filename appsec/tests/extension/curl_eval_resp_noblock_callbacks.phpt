--TEST--
Downstream curl requests: blocking verdicts on the response leave the app's callbacks alone
--DESCRIPTION--
With the app's own HEADERFUNCTION and WRITEFUNCTION, blocking verdicts on the
head and body calls are not acted on (G6.9, G10.14): the callbacks get every
header line and body byte, as in a run that the layer does not observe (the
reference run, before rinit()), and there is no block page.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\rinit;
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(['ok', 'ok', 'block', 'block'], false);
ce_print_at_shutdown($helper, $log);

function run($base)
{
    $lines = [];
    $body = '';
    $ch = curl_init("$base/json");
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$lines) {
        $lines[] = $line;
        return strlen($line);
    });
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$body) {
        $body .= $data;
        return strlen($data);
    });
    $ok = curl_exec($ch);
    return [$ok, curl_errno($ch), $lines, $body];
}

$reference = run($base);
rinit();
$observed = run($base);
echo "same result and bytes: ", $reference === $observed ? 'yes' : 'no', "\n";
echo "errno: ", $observed[1], " lines: ", count($observed[2]),
    " body: ", json_encode($observed[3]), "\n";
?>
--EXPECT--
same result and bytes: yes
errno: 0 lines: 5 body: "{\"status\":\"ok\",\"n\":42}"

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
== server log
GET /json
GET /json
