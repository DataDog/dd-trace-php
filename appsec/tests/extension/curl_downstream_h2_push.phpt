--TEST--
Downstream curl requests: HTTP/2 server push from an observed transfer
--DESCRIPTION--
G5.14, G5.4, Q5.3: libcurl creates the handle of a pushed stream by
duplicating the parent's while the parent is observed, so the pushed handle
carries our write and header functions; ext/curl re-points their data to the pushed handle's own
php_curl * before the app's callable runs. The layer does not interpose
CURLMOPT_PUSHFUNCTION: the app's callable gets the pushed handle as libcurl
made it, and the handle is not tracked. Our functions on it find no state for
its php_curl * and forward every call to ext/curl's: the pushed transfer
completes and the app gets its data. Nothing of it is attributed to the
parent: the calls of ours counted for the parent carry only the parent's own
head and body.
The server (inc/h2c_push_server.php) speaks HTTP/2 over cleartext (prior
knowledge). libcurl before 8.1.0 cannot handle a pushed :authority with a
port, so the URL uses port 80 and CURLOPT_CONNECT_TO the server's port;
7.88 also needs the pushed response after the parent's (delay).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the interposed write and header functions');
// PHP 7.1's ext/curl calls the push callable from a corrupted fcall_info
// (fatal error), with or without us
if (PHP_VERSION_ID < 70200) die('skip PHP 7.2+: ext/curl push callbacks');
if (!(curl_version()['features'] & CURL_VERSION_HTTP2)) die('skip libcurl without HTTP/2');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{curl_downstream_installed_count,curl_downstream_slots};
require __DIR__ . '/inc/curl_downstream.php';

function slots($ch)
{
    $s = curl_downstream_slots($ch);
    return "write={$s['write']} header={$s['header']} data={$s['data']}";
}

function h2_server_start($log)
{
    global $h2_proc, $h2_pipes;
    $cmd = [PHP_BINARY, '-n', __DIR__ . '/inc/h2c_push_server.php', $log, '127.0.0.1', '200'];
    if (PHP_VERSION_ID < 70400) {
        $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', $cmd));
    }
    $h2_proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $h2_pipes, null, []);
    $line = fgets($h2_pipes[1]);
    if (!preg_match('/^PORT (\d+)$/', trim((string)$line), $m)) {
        die("server did not start: " . var_export($line, true) . "\n");
    }
    register_shutdown_function(function () {
        global $h2_proc, $h2_pipes;
        fclose($h2_pipes[0]);
        fclose($h2_pipes[1]);
        proc_terminate($h2_proc);
        proc_close($h2_proc);
    });
    return (int)$m[1];
}

$slog = tempnam(sys_get_temp_dir(), 'h2log');
$port = h2_server_start($slog);
$pushed_handles = [];

$mh = curl_multi_init();
curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
curl_multi_setopt($mh, CURLMOPT_PUSHFUNCTION,
    function ($parent, $pushed, array $headers) use (&$pushed_handles) {
        echo "push: ", implode(' ', array_filter($headers, function ($h) {
            return strpos($h, ':path:') === 0;
        })), "\n";
        echo "pushed handle in the push callback: ", slots($pushed), "\n";
        $pushed_handles[] = $pushed;
        return CURL_PUSH_OK;
    });

$ch = curl_init('http://127.0.0.1/main');
curl_setopt_array($ch, [
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECT_TO => ["127.0.0.1:80:127.0.0.1:$port"],
]);
curl_multi_add_handle($mh, $ch);

$done = [];
$deadline = microtime(true) + 10;
do {
    $st = curl_multi_exec($mh, $running);
    while ($info = curl_multi_info_read($mh)) {
        $h = $info['handle'];
        $which = $h === $ch ? 'main' : (in_array($h, $pushed_handles, true) ? 'pushed' : 'unknown');
        $done[$which] = sprintf("%s: result=%d code=%d content=%s", $which, $info['result'],
            curl_getinfo($h, CURLINFO_RESPONSE_CODE), json_encode(curl_multi_getcontent($h)));
        curl_multi_remove_handle($mh, $h);
    }
    if ($running) {
        curl_multi_select($mh, 0.2);
    }
} while (($running || count($done) < 2) && $st === CURLM_OK && microtime(true) < $deadline);
ksort($done);
echo implode("\n", $done), "\n";
curl_multi_close($mh);
echo 'pushed handle tracked: ',
    cd_yn(datadog\appsec\testing\curl_downstream_dump($pushed_handles[0]) !== null), "\n";
@unlink($slog);
$d = datadog\appsec\testing\curl_downstream_dump($ch);
echo 'main observed=', cd_yn($d['observed']), ' hops=', $d['hops'], "\n";
// the pushed response is not attributed to the parent's collector (Q5.3)
echo 'main collected: ', $d['response']['status'], ' ', json_encode($d['response']['body']), "\n";
echo 'calls of ours for main: header lines=', $d['slot_calls']['header'],
    ' header bytes=', $d['slot_bytes']['header'], ' write bytes=', $d['slot_bytes']['write'], "\n";
echo 'main after its transfer: ', slots($ch), "\n";
echo 'installed: ', curl_downstream_installed_count(), "\n";
?>
--EXPECT--
push: :path:/pushed
pushed handle in the push callback: write=ours header=ours data=own
main: result=0 code=200 content="main body\n"
pushed: result=0 code=200 content="pushed body\n"
pushed handle tracked: no
main observed=yes hops=1
main collected: 200 "main body\n"
calls of ours for main: header lines=5 header bytes=74 write bytes=10
main after its transfer: write=pristine header=pristine data=own
installed: 0
