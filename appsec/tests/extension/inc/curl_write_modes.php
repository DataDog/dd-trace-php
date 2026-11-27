<?php
// What the app gets from curl_exec() in each output mode; printed the same
// way whether or not the transfer is observed (curl_downstream_write_modes_*).
// The output modes of G10.14: RETURNTRANSFER, default output (stdout),
// CURLOPT_FILE, WRITEFUNCTION with HEADERFUNCTION, and CURLOPT_WRITEHEADER;
// for each, the return value, the output, the error number and string.
require_once __DIR__ . '/curl_downstream_identity.php';
// every transfer observed, though no request is analysed (curl_eval.php turned
// it off)
datadog\appsec\testing\curl_downstream_collect_all(true);
$base = cd_server_start();
$h2c = ch_h2c_server_start();

$cases = [
    '/json' => ["$base/json", []],
    '/chunked' => ["$base/chunked", []],
    '/big?n=70000' => ["$base/big?n=70000", []],
    '/gzip' => ["$base/gzip", []],
    '/gzip decoded' => ["$base/gzip", [CURLOPT_ENCODING => '']],
    '/redirect' => ["$base/redirect", [CURLOPT_FOLLOWLOCATION => true]],
    '/len 100-continue' => ["$base/len", [CURLOPT_POSTFIELDS => 'a=b',
        CURLOPT_HTTPHEADER => ['Expect: 100-continue']]],
    'connection error' => ['http://127.0.0.1:1/json', []],
    'https' => ['https://example.test/json', ch_https_opts($base)],
    'h2c' => ["$h2c/main", [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE]],
];

function wm_result($ch, $ret)
{
    return var_export($ret, true) . ' errno=' . curl_errno($ch) . ' error="' . ch_error($ch) . '"';
}

foreach ($cases as $label => list($url, $opts)) {
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $r = curl_exec($ch);
    echo "$label RETURNTRANSFER: ", is_string($r) ? strlen($r) . ' ' . md5($r) : wm_result($ch, $r), "\n";
    $handles[] = $ch;

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    ob_start();
    $ret = curl_exec($ch);
    $r = ob_get_clean();
    echo "$label stdout: ", wm_result($ch, $ret), ' ', strlen($r), ' ', md5($r), "\n";
    $handles[] = $ch;

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $f = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_FILE, $f);
    $ret = curl_exec($ch);
    rewind($f);
    $r = stream_get_contents($f);
    echo "$label FILE: ", wm_result($ch, $ret), ' ', strlen($r), ' ', md5($r), "\n";
    $handles[] = $ch;

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $r = '';
    $hdrs = [];
    $calls = 0;
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$r, &$calls) {
        $r .= $data;
        $calls++;
        return strlen($data);
    });
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$hdrs) {
        if (trim($line) !== '') {
            $hdrs[] = trim($line);
        }
        return strlen($line);
    });
    $ret = curl_exec($ch);
    echo "$label WRITEFUNCTION: ", wm_result($ch, $ret), ' ', strlen($r), ' ', md5($r),
        ' calls>0=', var_export($calls > 0, true), "\n";
    echo "  headers: ", implode(' | ', $hdrs), "\n";
    $handles[] = $ch;

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $hf = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_WRITEHEADER, $hf);
    $ret = curl_exec($ch);
    rewind($hf);
    $h = stream_get_contents($hf);
    echo "$label WRITEHEADER: ", is_string($ret) ? strlen($ret) . ' ' . md5($ret) : wm_result($ch, $ret),
        ' header bytes: ', strlen($h), ' ', md5($h), "\n";
    $handles[] = $ch;
}
