--TEST--
The root span reference of a request whose startup failed is not released in the next request
--DESCRIPTION--
AppSec takes a reference on the root span in its RINIT and releases it in
its RSHUTDOWN. When the request startup fails after AppSec's RINIT, PHP
skips every RSHUTDOWN, but the built-in server keeps serving requests. The
span is then freed with the request heap or, where the built-in server does
not shut the failed request down at all (PHP 7.4 and 8.0), leaked. The next
request must not touch the old span. Here datadog.appsec.testing_abort_rinit
makes the first request fail in AppSec's RINIT (testing mode skips the
RSHUTDOWN that the real abort runs by hand), and the following requests go
to skip.php, which that option leaves alone. A first request to skip.php
makes the heap layout repeatable, so the next root span lands where the old
one was.
--SKIPIF--
<?php
if (!is_readable('/proc/self/cmdline')) die('skip needs /proc/self/cmdline');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
$args = explode("\0", rtrim(file_get_contents('/proc/self/cmdline'), "\0"));
$f = array_search('-f', $args, true);
if ($f !== false) {
    $args = array_slice($args, 0, $f);
} else {
    array_pop($args);
}

$docroot = sys_get_temp_dir() . '/appsec_failed_startup_' . getmypid();
@mkdir($docroot);
file_put_contents("$docroot/index.php", "<?php echo 'NOT REACHED';\n");
file_put_contents("$docroot/skip.php", "<?php echo 'OK';\n");
$stderr = "$docroot/server.stderr";
$appsec_log = "$docroot/appsec.log";

// Another process can take the free port before the server binds it, so a
// server that exits during startup is retried on a new port.
for ($attempt = 0; $attempt < 5; $attempt++) {
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);

    $cmd = $args;
    // AppSec logs to its own file. Its warnings must not reach the responses,
    // and where the built-in server skips the failed request's shutdown (PHP
    // 7.4, 8.0), the abort leaves error_reporting=0 behind, which would hide
    // them.
    array_push($cmd, '-d', 'datadog.appsec.testing_abort_rinit=1',
        '-d', "datadog.appsec.log_file=$appsec_log",
        '-S', "127.0.0.1:$port", '-t', $docroot);
    $cmd = implode(' ', array_map('escapeshellarg', $cmd));
    // env(1) sets USE_ZEND_ALLOC because getenv() with no arguments, needed to
    // pass a modified copy of the environment to proc_open, requires PHP 7.1.
    $server = proc_open("exec env USE_ZEND_ALLOC=1 $cmd", [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $stderr, 'w'],
        2 => ['file', $stderr, 'a'],
    ], $pipes);

    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port);
        if ($c) {
            fclose($c);
            break 2;
        }
        if (!proc_get_status($server)['running']) {
            proc_close($server);
            continue 2;
        }
        usleep(100000);
    }
    break;
}

function get($port, $path) {
    $ctx = stream_context_create(['http' => [
        'ignore_errors' => true, 'timeout' => 30]]);
    // The status line comes from the stream metadata because PHP 8.5
    // deprecates $http_response_header.
    $fp = @fopen("http://127.0.0.1:$port$path", 'r', false, $ctx);
    if (!$fp) {
        return ['no response', false];
    }
    $body = stream_get_contents($fp);
    $meta = stream_get_meta_data($fp);
    fclose($fp);
    $status = isset($meta['wrapper_data'][0]) ? $meta['wrapper_data'][0] : 'no response';
    return [$status, $body];
}

list($status, $body) = get($port, '/skip.php');
echo "first request: $status $body\n";
list($status, ) = get($port, '/index.php');
echo "failed startup: $status\n";
for ($i = 0; $i < 3; $i++) {
    list($status, $body) = get($port, '/skip.php');
    echo "next request: $status $body\n";
}

proc_terminate($server, SIGTERM);
for ($i = 0; $i < 100; $i++) {
    $st = proc_get_status($server);
    if (!$st['running']) {
        break;
    }
    usleep(100000);
}
echo "server killed by signal other than SIGTERM: ";
$crashed = $st['signaled'] && $st['termsig'] != SIGTERM;
var_dump($crashed);

$log = file_get_contents($stderr);
// ASAN does not see the stale access: zend_mm memory is not poisoned, and with
// the system allocator the old span is leaked rather than freed. The failure
// shows as an assertion on debug builds and as a crash on 7.4; on release
// builds only the count of requests that discarded stale state shows it.
$bad = preg_match_all('/Segmentation fault|Assertion .* failed|' .
    'heap corrupted|refcount|use-after-free|core dumped/i', $log, $m);
echo "memory errors in server output: ";
var_dump($bad > 0);
// Only the request right after the failed startup finds stale state.
echo "requests that discarded stale state: ",
    substr_count((string)@file_get_contents($appsec_log),
        "AppSec's shutdown did not run for the previous request"),
    "\n";
if ($bad || $crashed) {
    fwrite(STDERR, $log);
}
array_map('unlink', glob("$docroot/*"));
rmdir($docroot);
?>
--EXPECTF--
first request: HTTP/1.%d 200 OK OK
failed startup: HTTP/1.%d 403 Forbidden
next request: HTTP/1.%d 200 OK OK
next request: HTTP/1.%d 200 OK OK
next request: HTTP/1.%d 200 OK OK
server killed by signal other than SIGTERM: bool(false)
memory errors in server output: bool(false)
requests that discarded stale state: 1
