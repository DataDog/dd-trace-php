--TEST--
Downstream curl requests: a handle still in a multi starts no transfer
--DESCRIPTION--
libcurl keeps a handle in its multi until curl_multi_remove_handle(), also
once its transfer is done (or if it was not observed, e.g. added before
AppSec was active). Until then it refuses curl_exec() (CURLE_FAILED_INIT)
and adding it to a multi (CURLM_ADDED_ALREADY): no request is sent, so
none must be evaluated (nor installed, nor blocked on). Once removed, the
handle's transfers are evaluated again.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 30, 'ok'));

function run_multi($mh) {
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running);
    while (curl_multi_info_read($mh)) {}
}

function refused($label, $ch) {
    $r = curl_exec($ch);
    echo "$label: curl_exec: ", var_export($r, true), ' errno ', curl_errno($ch);
    $mh2 = curl_multi_init();
    echo ', add to another multi: ', curl_multi_add_handle($mh2, $ch), "\n";
}

// added while AppSec is not active (before rinit), still running after
$mh0 = curl_multi_init();
$ch0 = curl_init("$base/slow?n=0");
curl_setopt($ch0, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh0, $ch0);
curl_multi_exec($mh0, $running);
var_dump(rinit());
refused('not observed, running', $ch0);
run_multi($mh0);
refused('not observed, done', $ch0);

$mh = curl_multi_init();
$ch = curl_init("$base/json?n=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
run_multi($mh);
refused('observed, done', $ch);
curl_multi_remove_handle($mh, $ch);
curl_setopt($ch, CURLOPT_URL, "$base/json?n=2");
echo 'removed: ', curl_exec($ch), "\n";
var_dump(rshutdown());
$execs = ce_execs($helper);
foreach ($execs as list($id, $last, $data)) {
    if (isset($data['server.io.net.url'])) {
        echo "$id ", $data['server.io.net.url'], "\n";
    }
}
ce_check_closed($execs);
echo "== server log\n";
echo file_get_contents($log);
@unlink($log);
?>
--EXPECT--
bool(true)
not observed, running: curl_exec: false errno 2, add to another multi: 7
not observed, done: curl_exec: false errno 2, add to another multi: 7
observed, done: curl_exec: false errno 2, add to another multi: 7
removed: {"status":"ok","n":42}
bool(true)
curl-1-0 http://127.0.0.1:PORT/json?n=1
curl-2-0 http://127.0.0.1:PORT/json?n=2
2 subcontext(s), all closed
== server log
GET /slow?n=0
GET /json?n=1
GET /json?n=2
