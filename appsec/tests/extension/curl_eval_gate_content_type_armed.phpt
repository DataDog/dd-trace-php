--TEST--
Downstream curl requests: the read-function gate is armed for XML and urlencoded content types in any case
--DESCRIPTION--
G6.7: the Content-Type that arms the gate is matched like W1's: the header
name in any case, the media type in any case and before any parameter.
A text/xml and an application/x-www-form-urlencoded READFUNCTION upload
(header names "CONTENT-TYPE" and "content-type", values with mixed case
and a charset) both reach the gate: the app's callable is called while it
fills, the server gets the body unchanged and a request-stage call carries
the parsed body. The JSON case is in curl_eval_gate_put.phpt; the cases that
arm nothing in curl_eval_gate_content_type.phpt.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=100
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 10, 'ok'));
var_dump(rinit());

foreach ([
    'text/xml' => ['CONTENT-TYPE: Text/XML; charset=utf-8', ['<a>', 'xml</a>']],
    'urlencoded' => ['content-type: Application/X-WWW-Form-Urlencoded; charset=UTF-8', ['k=', 'v']],
] as $label => list($header, $pieces)) {
    $ch = curl_init("$base/echo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$header]);
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    $gate = [];
    $left = $pieces;
    curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$left, &$gate) {
        $gate[] = curl_downstream_dump($h)['gate'];
        return (string)array_shift($left);
    });
    $r = curl_exec($ch);
    echo "$label: ", $r === implode('', $pieces) ? 'unchanged' : var_export($r, true),
        '; gate during the reads: ', implode(', ', array_unique($gate)), "\n";
}

var_dump(rshutdown());
$execs = ce_execs($helper);
foreach ($execs as list($id, $last, $data)) {
    if (isset($data['server.io.net.request.body']) && !isset($data['server.io.net.url'])) {
        echo "$id gate call: ", json_encode($data['server.io.net.request.body']), "\n";
    }
}
ce_check_closed($execs);
?>
--EXPECTF--
bool(true)
text/xml: unchanged; gate during the reads: filling
urlencoded: unchanged; gate during the reads: filling
bool(true)
curl-1-0 gate call: %s
curl-2-0 gate call: {"k":"v"}
2 subcontext(s), all closed
