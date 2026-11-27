--TEST--
Downstream curl requests: the read-function gate is armed only for a content type the design analyses
--DESCRIPTION--
G6.7: in a sampled transfer whose body comes from CURLOPT_INFILE or
CURLOPT_READFUNCTION, the gate is armed only if the Content-Type of the
CURLOPT_HTTPHEADER lines (the one W1 reports; the header name in any case)
is JSON, XML or urlencoded. For application/octet-stream, or no
Content-Type, the read function forwards to ext/curl's at once: every call
of the app's callable is one read of libcurl (no read-ahead), the gate is
off, the server gets the body unchanged and no request-stage call carries
it. Such a transfer adds no body, so its slot of the per-request limit (1)
is given back when it ends (G8.11) and a later JSON transfer is analysed;
the metric counts that one transfer. The armed cases (JSON, XML,
urlencoded) are in curl_eval_gate_content_type_armed.phpt and the other
curl_eval_gate_* tests.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,root_span_get_metrics,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 20, 'ok'));

// the app's callable, which reports, for each call, the gate's state and how
// many reads libcurl had made of the interposed read function before it
function reader(array $pieces, array &$log) {
    return function ($h, $fd, $len) use (&$pieces, &$log) {
        $d = curl_downstream_dump($h);
        $log[] = "{$d['gate']}/{$d['slot_calls']['read']}";
        return (string)array_shift($pieces);
    };
}

function upload($base, array $headers, $setup) {
    $ch = curl_init("$base/echo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    $setup($ch);
    return curl_exec($ch);
}

var_dump(rinit());

$body = '{"a":"octet-stream infile"}';
$infile = tempnam(sys_get_temp_dir(), 'cdin');
file_put_contents($infile, $body);
$fp = fopen($infile, 'rb');
$r = upload($base, ['Content-Type: application/octet-stream'],
    function ($ch) use ($fp, $body) {
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($body));
    });
echo 'INFILE octet-stream: ', $r === $body ? 'unchanged' : var_export($r, true), "\n";
fclose($fp);
@unlink($infile);

foreach ([
    'READFUNCTION octet-stream' => ['Content-Type: application/octet-stream'],
    'READFUNCTION no content type' => ['X-Other: 1'],
] as $label => $headers) {
    $pieces = ['{"a":', '"read', 'function"}'];
    $log = [];
    $r = upload($base, $headers, function ($ch) use ($pieces, &$log) {
        curl_setopt($ch, CURLOPT_READFUNCTION, reader($pieces, $log));
    });
    echo "$label: ", $r === implode('', $pieces) ? 'unchanged' : var_export($r, true),
        '; calls (gate/reads before): ', implode(' ', $log), "\n";
}

$ch = curl_init("$base/echo");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{"later":"json"}');
echo 'POSTFIELDS json: ', curl_exec($ch), "\n";

var_dump(rshutdown());
var_dump(root_span_get_metrics()['_dd.appsec.downstream_request'] ?? null);

$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
INFILE octet-stream: unchanged
READFUNCTION octet-stream: unchanged; calls (gate/reads before): off/0 off/1 off/2 off/3
READFUNCTION no content type: unchanged; calls (gate/reads before): off/0 off/1 off/2 off/3
POSTFIELDS json: {"later":"json"}
bool(true)
float(1)
curl-1-0 {"server.io.net.request.headers":{"content-type":["application/octet-stream"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["27"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"content-type":["application/octet-stream"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["20"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-3-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"],"x-other":["1"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["20"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-4-0 {"server.io.net.request.body":{"later":"json"},"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-4-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["16"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
4 subcontext(s), all closed
