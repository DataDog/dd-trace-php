--TEST--
Downstream curl requests: CURLOPT_POSTFIELDS is evaluated before sending only if libcurl sends it
--DESCRIPTION--
After CURLOPT_POSTFIELDS, CURLOPT_UPLOAD makes libcurl send a PUT with the
READFUNCTION/INFILE body, CURLOPT_HTTPGET or CURLOPT_UPLOAD=false a GET
without a body, and CURLOPT_POST after an array POSTFIELDS a POST with the
READFUNCTION body (CURLOPT_POSTFIELDS after CURLOPT_UPLOAD: see
curl_eval_presend_upload.phpt). The single pre-send evaluation (W1) must then
not include POSTFIELDS. A body that comes from READFUNCTION or INFILE has no
source in the options, so W1 carries none either, and with POSTFIELDS set
the read-function gate is not armed (G6.7): no call carries that body. The
server log shows what libcurl sent.
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
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 30, 'ok'), false);
var_dump(rinit());

$json = ['Content-Type: application/json'];
$infile = tempnam(sys_get_temp_dir(), 'cdin');
file_put_contents($infile, '{"from":"infile"}');
$cases = [
    'upload' => function ($ch) use ($infile, $json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $json);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{"from":"postfields"}');
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, fopen($infile, 'rb'));
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($infile));
    },
    'httpget' => function ($ch) use ($json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $json);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{"from":"postfields"}');
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    },
    'upload off' => function ($ch) use ($json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $json);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{"from":"postfields"}');
        curl_setopt($ch, CURLOPT_UPLOAD, false);
    },
    'post after array' => function ($ch) use ($json) {
        // explicitly chunked: libcurl 7.61 would otherwise send no body
        // (no POSTFIELDSIZE: neither a Content-Length nor chunks)
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            array_merge($json, ['Transfer-Encoding: chunked']));
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['from' => 'postfields']);
        curl_setopt($ch, CURLOPT_POST, true);
        $sent = false;
        curl_setopt($ch, CURLOPT_READFUNCTION, function ($ch, $fd, $n) use (&$sent) {
            if ($sent) {
                return '';
            }
            $sent = true;
            return '{"from":"readfunction"}';
        });
    },
    'nobody then postfields' => function ($ch) use ($json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $json);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{"from":"postfields"}');
    },
    'nobody then array postfields' => function ($ch) use ($json) {
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['from' => 'postfields']);
    },
    'nobody off after postfields' => function ($ch) use ($json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $json);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{"from":"postfields"}');
        curl_setopt($ch, CURLOPT_NOBODY, false);
    },
];
foreach ($cases as $label => $setup) {
    $ch = curl_init("$base/echo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $setup($ch);
    $r = curl_exec($ch);
    echo "$label: ", var_export(preg_replace('/-{2,}[0-9a-zA-Z]+/', 'BOUNDARY', $r), true), "\n";
}
unlink($infile);
echo file_get_contents($log);
@unlink($log);
foreach (ce_execs($helper) as list($id, $last, $data)) {
    $out = [];
    foreach (['server.io.net.request.method', 'server.io.net.request.body'] as $k) {
        if (isset($data[$k])) {
            $out[substr($k, strrpos($k, '.') + 1)] = $data[$k];
        }
    }
    if (isset($data['server.io.net.request.headers'])) {
        $h = $data['server.io.net.request.headers'];
        $out['content-type'] = isset($h['content-type']) ? $h['content-type'] : null;
    }
    if ($out) {
        echo $id, ' ', json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
    }
}
?>
--EXPECT--
bool(true)
upload: '{"from":"infile"}'
httpget: ''
upload off: ''
post after array: '{"from":"readfunction"}'
nobody then postfields: ''
nobody then array postfields: 'BOUNDARY
Content-Disposition: form-data; name="from"

postfields
BOUNDARY--
'
nobody off after postfields: '{"from":"postfields"}'
PUT /echo
GET /echo
GET /echo
POST /echo
HEAD /echo
POST /echo
POST /echo
curl-1-0 {"method":"PUT","content-type":["application/json"]}
curl-2-0 {"method":"GET","content-type":["application/json"]}
curl-3-0 {"method":"GET","content-type":["application/json"]}
curl-4-0 {"method":"POST","content-type":["application/json"]}
curl-5-0 {"method":"HEAD","content-type":["application/json"]}
curl-6-0 {"method":"POST","body":{"from":"postfields"},"content-type":["multipart/form-data"]}
curl-7-0 {"method":"POST","body":{"from":"postfields"},"content-type":["application/json"]}
