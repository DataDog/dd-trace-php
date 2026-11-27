--TEST--
Downstream curl requests: CURL_READFUNC_ABORT, CURL_READFUNC_PAUSE and other values above the ask pass through the read-function gate
--DESCRIPTION--
G6.7 exception: when the original read function returns CURL_READFUNC_ABORT
or CURL_READFUNC_PAUSE while the gate fills its buffer, the wrapper returns
it at once, with no WAF call: ABORT ends the transfer with
CURLE_ABORTED_BY_CALLBACK, also after bytes were held; after PAUSE libcurl
calls again once unpaused, and the gate goes on filling with the bytes it
held, so the body is analysed and sent whole. Any other value above what
was asked is passed on unchanged, and libcurl fails with CURLE_READ_ERROR;
nothing is analysed. ext/curl passes an int returned by the callable through
from PHP 8.1.17 and 8.2.4 (GH-10270).
Up to libcurl 8.6 libcurl asks for its whole buffer and the gate for no more
than what is left of the upload size: an int above the gate's ask but within
libcurl's buffer, after bytes were held, would make libcurl take that many
bytes of its buffer in place of the bytes the gate holds, so the gate
returns CURL_READFUNC_ABORT instead (CURLE_ABORTED_BY_CALLBACK): a body the
gate changed is never sent. From 8.7 libcurl asks only for what is left, so
the same int is above its ask: CURLE_READ_ERROR.
From PHP 8.6 ext/curl throws a ValueError for such an int and aborts the
transfer (CURLE_ABORTED_BY_CALLBACK) in both cases, before the gate sees it.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
if (PHP_VERSION_ID < 80117 || (PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80204)) {
    die('skip ext/curl passes ints from the READFUNCTION from 8.1.17 and 8.2.4');
}
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
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 20, 'ok'));
var_dump(rinit());
const ABORT = 0x10000000;

$cases = [
    'abort first' => [ABORT],
    'abort after 5 bytes' => ['{"a":', ABORT],
    'pause first' => [CURL_READFUNC_PAUSE, '{"a":', '"pp"}'],
    'pause after 5 bytes' => ['{"a":', CURL_READFUNC_PAUSE, '"pp"}'],
    'above the ask' => [1 << 24],
    'above the ask, within the buffer, after 5 bytes' => ['{"a":', 6],
];
$new = datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'] >= 0x080700;
foreach ($cases as $label => $pieces) {
    $paused = false;
    $ch = curl_init("$base/echo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_INFILESIZE, 10);
    curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$pieces, &$paused) {
        $r = array_shift($pieces);
        if ($r === CURL_READFUNC_PAUSE) {
            $paused = true;
        }
        return $r === null ? '' : $r;
    });
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    // (CURLOPT_XFERINFOFUNCTION exists from PHP 8.2 only)
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($h) use (&$paused) {
        if ($paused) {
            $paused = false;
            curl_pause($h, CURLPAUSE_CONT);
        }
        return 0;
    });
    try {
        $r = curl_exec($ch);
    } catch (ValueError $e) {
        // PHP 8.6 rejects an int other than 0, ABORT and PAUSE
        $r = false;
    }
    $errno = curl_errno($ch);
    $php86 = PHP_VERSION_ID >= 80600;
    if ($php86 && $label === 'above the ask' && $errno === 42) {
        $errno = 26;
    }
    if ($label === 'above the ask, within the buffer, after 5 bytes') {
        $errno = $errno === ($new && !$php86 ? 26 : 42) ? 'as expected for this libcurl' : $errno;
    }
    echo "$label: ", var_export($r, true), ' errno ', $errno, "\n";
}

var_dump(rshutdown());
$execs = ce_execs($helper);
foreach ($execs as list($id, $last, $data)) {
    // W1 and the response calls are as usual; print the others
    if (!isset($data['server.io.net.url']) && !isset($data['server.io.net.response.status'])) {
        echo $id, $last ? ' last' : '', ' ', json_encode($data), "\n";
    }
}
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
abort first: false errno 42
abort after 5 bytes: false errno 42
pause first: '{"a":"pp"}' errno 0
pause after 5 bytes: '{"a":"pp"}' errno 0
above the ask: false errno 26
above the ask, within the buffer, after 5 bytes: false errno as expected for this libcurl
bool(true)
curl-1-0 last []
curl-2-0 last []
curl-3-0 {"server.io.net.request.body":{"a":"pp"}}
curl-4-0 {"server.io.net.request.body":{"a":"pp"}}
curl-5-0 last []
curl-6-0 last []
6 subcontext(s), all closed
