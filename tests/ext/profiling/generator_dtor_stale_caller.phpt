--TEST--
Aborted generator end hooks must not expose returned caller frames to profiling
--DESCRIPTION--
A generator keeps borrowed prev_execute_data pointers after suspension. Its
resumer can return before request shutdown. The tracer's generator destructor
copies that obsolete ancestry and passes it to the profiler before the end hook.

Use the CLI server: the profiler deliberately does not cache function names in
ordinary CLI processes. No pointers are modified by this test. The HTTP child
must survive request teardown, and every aborted generator must run its end hook
exactly once. Requires the standalone profiler to be loaded by the test runner.
--SKIPIF--
<?php
if (PHP_OS === 'WINNT') die('skip: requires Unix process handling');
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!extension_loaded('datadog-profiling')) die('skip: standalone profiler required');
if (!function_exists('DDTrace\install_hook')) die('skip: install_hook required');
if (!function_exists('proc_open')) die('skip: proc_open required');
if (!function_exists('stream_socket_server')) die('skip: stream sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
if (getenv('PHP_PEAR_RUNTESTS') === '1') die('skip: pecl run-tests does not support child PHP arguments');
?>
--ENV--
DD_PROFILING_ENABLED=0
DD_TRACE_CLI_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_STARTUP_LOGS=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_REMOTE_CONFIGURATION_ENABLED=0
DD_APPSEC_ENABLED=0
--INI--
opcache.jit=disable
datadog.trace.generate_root_span=0
datadog.trace.hook_limit=1000000
--FILE--
<?php
$directory = tempnam(sys_get_temp_dir(), 'ddtrace-generator-dtor-');
unlink($directory);
mkdir($directory, 0700);
// Only the HTTP child samples; ordinary CLI deliberately ignores profiler caches.
putenv('DD_PROFILING_ENABLED=1');
putenv('DD_TRACE_CLI_ENABLED=1');
$stderrFile = $directory . '/stderr.log';
$stdoutFile = $directory . '/stdout.log';
$endHooksFile = $directory . '/end-hooks';

file_put_contents($directory . '/ready.php', <<<'PHP'
<?php
echo extension_loaded('ddtrace') && extension_loaded('datadog-profiling')
    && ini_get('datadog.profiling.enabled') ? "ready\n" : "missing extensions\n";
PHP
);
file_put_contents($directory . '/generator.php', <<<'PHP'
<?php
$functions = $generators = [];
for ($i = 0; $i < 16; ++$i) {
    $function = function (): Generator { yield 1; };
    // Keep the generator's own function alive; only its caller is discarded.
    $functions[] = $function;
    DDTrace\install_hook($function, null, static function ($hook) {
        file_put_contents(__DIR__ . '/end-hooks', '.', FILE_APPEND);
    }, DDTrace\HOOK_INSTANCE);
    $caller = function ($function): Generator {
        $generator = $function();
        $generator->current();
        return $generator;
    };
    $generators[] = $caller($function);
    unset($caller);
}
// Response output precedes teardown: the parent also checks completion marks
// and the child process, rather than treating this body as proof of safety.
echo "ok\n";
PHP
);

$listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!$listener) throw new RuntimeException("Cannot reserve server port: $errorMessage");
$address = stream_socket_get_name($listener, false);
fclose($listener);
$port = substr($address, strrpos($address, ':') + 1);

// Preserve the runner's PHP/extension configuration, including the profiler.
// A crashing unfixed child must not spend minutes writing a large core dump.
$command = 'ulimit -c 0; exec ' . escapeshellarg(getenv('TEST_PHP_EXECUTABLE')) . ' '
    . getenv('TEST_PHP_ARGS') . ' ' . getenv('TEST_PHP_EXTRA_ARGS')
    . ' -d opcache.jit=disable -d datadog.trace.hook_limit=1000000'
    . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($directory);
putenv('PHP_CLI_SERVER_WORKERS'); // One persistent PHP process must handle all requests.
$process = proc_open($command, [['pipe', 'r'], ['file', $stdoutFile, 'w'], ['file', $stderrFile, 'w']], $pipes);
if (!is_resource($process)) throw new RuntimeException('Cannot start child PHP server');
fclose($pipes[0]);

function requestGeneratorTest($port, $path) {
    $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 0.2);
    if (!$connection) return false;
    stream_set_timeout($connection, 5);
    fwrite($connection, "GET /$path HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $response = stream_get_contents($connection);
    $metadata = stream_get_meta_data($connection);
    fclose($connection);
    if ($metadata['timed_out'] || strpos($response, 'HTTP/1.0 200') !== 0) return false;
    $parts = explode("\r\n\r\n", $response, 2);
    return isset($parts[1]) ? $parts[1] : false;
}

try {
    $ready = false;
    $deadline = microtime(true) + 30;
    do {
        if (requestGeneratorTest($port, 'ready.php') === "ready\n") {
            $ready = true;
            break;
        }
        if (!proc_get_status($process)['running']) break;
        usleep(10000);
    } while (microtime(true) < $deadline);
    if (!$ready) throw new RuntimeException('Child server did not initialize tracer and profiler');

    $requests = 2048;
    for ($i = 0; $i < $requests; ++$i) {
        if (requestGeneratorTest($port, 'generator.php') !== "ok\n") {
            throw new RuntimeException("Generator request $i failed; child may have crashed during teardown");
        }
    }

    // Ensure the final response was followed by all destructor/end callbacks.
    $expected = $requests * 16;
    $deadline = microtime(true) + 5;
    do {
        clearstatcache(true, $endHooksFile);
        $count = is_file($endHooksFile) ? filesize($endHooksFile) : 0;
        if (!proc_get_status($process)['running']) throw new RuntimeException('Child crashed after sending its response');
        if ($count === $expected) break;
        usleep(1000);
    } while (microtime(true) < $deadline);
    if ($count !== $expected) throw new RuntimeException("Expected $expected end hooks, got $count");

    echo "Child survived generator teardown\n";
    echo "End hooks: $count\n";
} catch (Throwable $error) {
    echo $error->getMessage(), "\n";
    // Preserve native/sanitizer diagnostics in the PHPT failure output.
    $diagnostics = file_get_contents($stderrFile);
    echo substr($diagnostics, -16384);
} finally {
    // Request teardown was checked above; do not test or wait for process-level
    // shutdown of the CLI server and its background profiling threads here.
    if (proc_get_status($process)['running']) proc_terminate($process, 9);
    proc_close($process);
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
?>
--EXPECT--
Child survived generator teardown
End hooks: 32768
