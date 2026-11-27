--TEST--
Downstream curl requests: debug options the app sets while a transfer is observed
--DESCRIPTION--
G5.5: CURLINFO_HEADER_OUT and CURLOPT_VERBOSE set by the app, also after the
transfer started (after curl_multi_add_handle(), or from a callback during
curl_exec()), are left alone: the layer has no debug callback to replace
them. CURLINFO_HEADER_OUT gives the request head and the verbose output goes
to CURLOPT_STDERR, as without AppSec, and every transfer is observed (a
pre-send and a response call each).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response the collector holds');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
// the dumps show the bodies too
datadog\appsec\testing\curl_downstream_collect_all(true);
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 60, 'ok'), false);
var_dump(rinit());

function run_multi($mh)
{
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status == CURLM_OK);
}

function head_out($ch)
{
    $h = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    return is_string($h) ? strtok($h, "\r\n") : var_export($h, true);
}

function verbose_has_request($file)
{
    $out = file_get_contents($file);
    @unlink($file);
    return cd_yn(strpos($out, '> GET /json HTTP/1.1') !== false);
}

// a progress callback that calls $set once
function once_in_progress($ch, callable $set)
{
    $done = false;
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function () use (&$done, $set) {
        if (!$done) {
            $done = true;
            $set();
        }
        return 0;
    });
}

echo "== multi: CURLINFO_HEADER_OUT after curl_multi_add_handle\n";
$mh = curl_multi_init();
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
curl_setopt($ch, CURLINFO_HEADER_OUT, true);
run_multi($mh);
curl_multi_remove_handle($mh, $ch);
echo head_out($ch), "\n";

echo "== curl_exec: CURLINFO_HEADER_OUT from a callback, then another curl_exec\n";
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
once_in_progress($ch, function () use (&$ch) {
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
});
curl_exec($ch);
curl_exec($ch);
echo head_out($ch), "\n";

echo "== multi: CURLOPT_VERBOSE after curl_multi_add_handle\n";
$errf = tempnam(sys_get_temp_dir(), 'verbose');
$fp = fopen($errf, 'w+');
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_STDERR, $fp);
curl_multi_add_handle($mh, $ch);
curl_setopt($ch, CURLOPT_VERBOSE, true);
run_multi($mh);
curl_multi_remove_handle($mh, $ch);
fclose($fp);
echo 'verbose output has the request: ', verbose_has_request($errf), "\n";

echo "== curl_exec: CURLOPT_VERBOSE from a callback, then another curl_exec\n";
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// the first transfer's verbose output goes to the app's CURLOPT_STDERR, as
// without AppSec (we install no DEBUGFUNCTION that would take it)
$errf = tempnam(sys_get_temp_dir(), 'verbose');
$fp = fopen($errf, 'w+');
curl_setopt($ch, CURLOPT_STDERR, $fp);
once_in_progress($ch, function () use (&$ch) {
    curl_setopt($ch, CURLOPT_VERBOSE, true);
});
curl_exec($ch);
fclose($fp);
echo 'verbose output of the 1st transfer has the request: ', verbose_has_request($errf), "\n";
$errf = tempnam(sys_get_temp_dir(), 'verbose');
$fp = fopen($errf, 'w+');
curl_setopt($ch, CURLOPT_STDERR, $fp);
curl_exec($ch);
fclose($fp);
echo 'verbose output of the 2nd transfer has the request: ', verbose_has_request($errf), "\n";

echo "== multi: CURLOPT_VERBOSE off after curl_multi_add_handle\n";
// still observed; afterwards, VERBOSE is off as the app set it
$errf = tempnam(sys_get_temp_dir(), 'verbose');
$fp = fopen($errf, 'w+');
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_STDERR, $fp);
curl_multi_add_handle($mh, $ch);
curl_setopt($ch, CURLOPT_VERBOSE, false);
run_multi($mh);
curl_multi_remove_handle($mh, $ch);
cd_dump($ch);
// a transfer that is not observed (no request is being analysed any more)
// shows what VERBOSE was left at
rshutdown();
curl_exec($ch);
fclose($fp);
echo 'verbose output: ', strlen(file_get_contents($errf)), " bytes\n";
@unlink($errf);
// 7 transfers: the pre-send and the response call each
echo count(ce_execs($helper)), " request_exec call(s)\n";
?>
--EXPECTF--
bool(true)
== multi: CURLINFO_HEADER_OUT after curl_multi_add_handle
GET /json HTTP/1.1
== curl_exec: CURLINFO_HEADER_OUT from a callback, then another curl_exec
GET /json HTTP/1.1
== multi: CURLOPT_VERBOSE after curl_multi_add_handle
verbose output has the request: yes
== curl_exec: CURLOPT_VERBOSE from a callback, then another curl_exec
verbose output of the 1st transfer has the request: yes
verbose output of the 2nd transfer has the request: yes
== multi: CURLOPT_VERBOSE off after curl_multi_add_handle
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=no truncated=no
verbose output: 0 bytes
14 request_exec call(s)
