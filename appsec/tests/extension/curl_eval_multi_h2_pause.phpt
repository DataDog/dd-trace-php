--TEST--
Downstream curl requests: a multi transfer over HTTP/2 whose write the app pauses
--DESCRIPTION--
Q5.5, G5.3: the app's WRITEFUNCTION returns CURL_WRITEFUNC_PAUSE for the
body of an HTTP/2 transfer driven by a multi handle, and unpauses it with
curl_pause() between two curl_multi_exec() calls. The data reaches the app
once, the transfer completes, and it gets W1 at the first exec and its
response call (head only: text/plain) at the exec that finds nothing running,
once.
The server (inc/h2c_push_server.php) speaks HTTP/2 over cleartext (prior
knowledge); the multi handle has no push function, so the client disables
push.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
if (!(curl_version()['features'] & CURL_VERSION_HTTP2)) die('skip libcurl without HTTP/2');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';

function h2_server_start($log)
{
    global $h2_proc, $h2_pipes;
    $cmd = [PHP_BINARY, '-n', __DIR__ . '/inc/h2c_push_server.php', $log];
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
$helper = ce_helper(array_fill(0, 8, 'ok'));
rinit();

$pause = defined('CURL_WRITEFUNC_PAUSE') ? CURL_WRITEFUNC_PAUSE : 0x10000001;
$paused = 0;
$got = '';
$mh = curl_multi_init();
curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
$ch = curl_init("http://127.0.0.1:$port/main");
curl_setopt_array($ch, [
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
    CURLOPT_WRITEFUNCTION => function ($h, $data) use (&$paused, &$got, $pause) {
        if ($paused === 0) {
            $paused = 1;
            return $pause;
        }
        $got .= $data;
        return strlen($data);
    },
]);
curl_multi_add_handle($mh, $ch);
$deadline = microtime(true) + 10;
do {
    $status = curl_multi_exec($mh, $running);
    if ($paused === 1) {
        $paused = 2;
        curl_pause($ch, CURLPAUSE_CONT);
    }
    if ($running) {
        curl_multi_select($mh, 0.2);
    }
} while ($running && $status === CURLM_OK && microtime(true) < $deadline);
echo "paused and resumed: ", $paused === 2 ? 'yes' : 'no', "\n";
echo "running: $running\n";
var_dump($got);
curl_multi_remove_handle($mh, $ch);
curl_multi_close($mh);
@unlink($slog);

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
paused and resumed: yes
running: 0
string(10) "main body
"
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/main"}
curl-1-0 last {"server.io.net.response.headers":{"content-length":["10"],"content-type":["text/plain"],"x-main":["yes"]},"server.io.net.response.status":"200"}
1 subcontext(s), all closed
