--TEST--
The root span reference of a request whose startup failed is not released in the next request
--DESCRIPTION--
AppSec takes a reference on the root span in its RINIT and releases it in its
RSHUTDOWN. When a request's startup fails after AppSec's RINIT, PHP skips every
RSHUTDOWN, but the built-in server keeps serving requests. The span then went
away with the failed request's heap (or leaked, on PHP 7.4 and 8.0, where the
built-in server does not shut that request down at all), and the next request
must not touch it.

The test starts a built-in server with datadog.appsec.testing_abort_rinit=1,
which makes AppSec's RINIT block every script but skip.php. In testing mode the
block skips the RSHUTDOWN that AppSec otherwise runs by hand, so it stands in
for a failure in the RINIT of an extension loaded after AppSec. The server gets:
1. skip.php, so that the heap layout is the same on every run and the next
   root span takes the place of the failed request's one;
2. index.php, whose startup fails;
3. skip.php three times. Only the first of these may find stale state.
--SKIPIF--
<?php
if (!is_readable('/proc/self/cmdline')) die('skip needs /proc/self/cmdline');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
// This test's PHP command line without the script, so that the server runs
// with the same extensions and settings.
function php_command() {
    $args = explode("\0", rtrim(file_get_contents('/proc/self/cmdline'), "\0"));
    $f = array_search('-f', $args, true);
    return $f !== false ? array_slice($args, 0, $f) : array_slice($args, 0, -1);
}

// Returns the server process and its port. Another process can take the free
// port before the server binds it, so a server that exits is retried.
function start_server(array $cmd, $output_file) {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);

        $cmd_str = implode(' ', array_map('escapeshellarg',
            array_merge($cmd, ['-S', "127.0.0.1:$port"])));
        // exec, so that proc_terminate() signals PHP rather than the shell
        $proc = proc_open("exec $cmd_str", [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $output_file, 'w'],
            2 => ['file', $output_file, 'a'],
        ], $pipes);

        for ($i = 0; $i < 100; $i++) {
            if ($c = @fsockopen('127.0.0.1', $port)) {
                fclose($c);
                return [$proc, $port];
            }
            if (!proc_get_status($proc)['running']) {
                proc_close($proc);
                continue 2;
            }
            usleep(100000);
        }
        break;
    }
    die("the server did not start\n");
}

// Returns the status line and the body.
function get($port, $path) {
    $ctx = stream_context_create(['http' => [
        'ignore_errors' => true, 'timeout' => 30]]);
    $fp = @fopen("http://127.0.0.1:$port$path", 'r', false, $ctx);
    if (!$fp) {
        return ['no response', ''];
    }
    $body = stream_get_contents($fp);
    // not $http_response_header, which PHP 8.5 deprecates
    $headers = stream_get_meta_data($fp)['wrapper_data'];
    fclose($fp);
    return [$headers[0], $body];
}

// Returns whether the server died of a signal other than SIGTERM.
function stop_server($proc) {
    proc_terminate($proc, SIGTERM);
    for ($i = 0; $i < 100; $i++) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            return $st['signaled'] && $st['termsig'] != SIGTERM;
        }
        usleep(100000);
    }
    return false;
}

$docroot = sys_get_temp_dir() . '/appsec_failed_startup_' . getmypid();
@mkdir($docroot);
file_put_contents("$docroot/index.php", "<?php echo 'NOT REACHED';\n");
file_put_contents("$docroot/skip.php", "<?php echo 'OK';\n");
$server_output = "$docroot/server.out";
$appsec_log = "$docroot/appsec.log";

// With the system allocator, the failed request's span is leaked rather than
// freed with its heap.
putenv('USE_ZEND_ALLOC=1');
// AppSec logs to a file: otherwise its warnings would go to the responses, or
// be hidden by the error_reporting=0 that the abort leaves behind on PHP 7.4
// and 8.0.
list($server, $port) = start_server(array_merge(php_command(), [
    '-d', 'datadog.appsec.testing_abort_rinit=1',
    '-d', "datadog.appsec.log_file=$appsec_log",
    '-t', $docroot,
]), $server_output);

list($status, $body) = get($port, '/skip.php');
echo "first request: $status $body\n";
list($status, ) = get($port, '/index.php');
echo "failed startup: $status\n";
for ($i = 0; $i < 3; $i++) {
    list($status, $body) = get($port, '/skip.php');
    echo "next request: $status $body\n";
}

$crashed = stop_server($server);
echo "server killed by signal other than SIGTERM: ";
var_dump($crashed);

// Without the fix, debug builds fail an assertion and PHP 7.4 crashes. Release
// builds only fail the count of requests that found stale state: ASAN does not
// see into PHP's allocator.
$output = file_get_contents($server_output);
$bad = preg_match('/Segmentation fault|Assertion .* failed|' .
    'heap corrupted|refcount|use-after-free|core dumped/i', $output);
echo "memory errors in server output: ";
var_dump($bad === 1);
echo "requests that found stale state: ",
    substr_count((string)@file_get_contents($appsec_log),
        "AppSec's shutdown did not run for the previous request"),
    "\n";

if ($bad || $crashed) {
    fwrite(STDERR, $output);
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
requests that found stale state: 1
