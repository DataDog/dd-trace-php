--TEST--
Downstream curl requests: which transfers call the interposed read function, and which reach the read-function gate
--DESCRIPTION--
G10.12: the interposed read function is called (with the body's bytes) for
a body from CURLOPT_INFILE (an upload with INFILESIZE > 0) and from the
app's CURLOPT_READFUNCTION (here a POST, sent chunked); not for a string
CURLOPT_POSTFIELDS (libcurl sends it from memory) nor for an array one
(libcurl's mime reader replaces the read function). Only the first two
reach the gate: their request-stage call with the body is made in hop 0's
subcontext; the POSTFIELDS bodies are in W1.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
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
$helper = ce_helper(array_fill(0, 20, 'ok'));
var_dump(rinit());

$json = '{"k":"v"}';
$infile = tempnam(sys_get_temp_dir(), 'cdin');
file_put_contents($infile, $json);
$fp = fopen($infile, 'rb');
$cases = [
    'string POSTFIELDS' => function ($ch) use ($json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    },
    'array POSTFIELDS' => function ($ch) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['k' => 'v']);
    },
    'INFILE' => function ($ch) use ($json, $fp) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($json));
    },
    'READFUNCTION' => function ($ch) use ($json) {
        $pieces = [$json];
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$pieces) {
            return (string)array_shift($pieces);
        });
    },
];
foreach ($cases as $label => $setup) {
    $ch = curl_init("$base/len");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $setup($ch);
    $r = curl_exec($ch);
    $d = curl_downstream_dump($ch);
    $calls = ce_execs($helper);
    $where = [];
    foreach ($calls as list($id, $last, $data)) {
        if (isset($data['server.io.net.request.body'])) {
            $where[] = (isset($data['server.io.net.url']) ? 'W1' : 'gate') . " in $id";
        }
    }
    echo "$label: $r; read function: ",
        $d['slot_calls']['read'] > 0 ? "called, {$d['slot_bytes']['read']} bytes" : 'not called',
        '; body analysed: ', $where ? implode(', ', $where) : 'no', "\n";
}
fclose($fp);
@unlink($infile);
var_dump(rshutdown());
?>
--EXPECTF--
bool(true)
string POSTFIELDS: received 9 bytes; read function: not called; body analysed: W1 in curl-1-0
array POSTFIELDS: received %d bytes; read function: not called; body analysed: W1 in curl-2-0
INFILE: received 9 bytes; read function: called, 9 bytes; body analysed: gate in curl-3-0
READFUNCTION: received 9 bytes; read function: called, 9 bytes; body analysed: gate in curl-4-0
bool(true)
