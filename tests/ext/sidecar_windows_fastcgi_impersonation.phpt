--TEST--
Traces are sent when php-cgi impersonates the FastCGI client (IIS fastcgi.impersonate=1)
--DESCRIPTION--
IIS with fastcgi.impersonate=1 makes php-cgi impersonate the request user (e.g. IUSR) on the
request thread before php_request_startup(), so the lazy sidecar setup of the first request runs
impersonated. Reproduce that with php-cgi -b on a named pipe and a client connecting as a
different, unprivileged local user.
--SKIPIF--
<?php
if (PHP_OS_FAMILY !== 'Windows') die('skip: Windows only');
if (!is_file(dirname(PHP_BINARY) . '\\php-cgi.exe')) die('skip: php-cgi.exe required');
// High (or System) mandatory level: needed to create a local user.
if (!preg_match('/S-1-16-(12288|16384)/', (string)shell_exec('whoami /groups'))) die('skip: must run elevated to create a local user');
include __DIR__ . '/includes/skipif_no_dev_env.inc';
?>
--ENV--
DD_TRACE_CLI_ENABLED=0
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
--INI--
datadog.trace.agent_test_session_token=tests/ext/sidecar_windows_fastcgi_impersonation.phpt
--FILE--
<?php

include __DIR__ . '/includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->replayAllRequests(); // cleanup possible leftovers

$service = 'fcgi-impersonation-' . getmypid();
$user = 'ddfcgi' . substr(md5(uniqid('', true)), 0, 8);
// <= 14 chars (else net user prompts) and no characters escapeshellarg() strips on Windows.
$password = 'Dd-' . bin2hex(random_bytes(4)) . 'a1';
$pipe = '\\\\.\\pipe\\dd-fcgi-impersonation-' . getmypid();

// Readable/writable by the impersonated user, like an IIS site root.
$dir = 'C:\\dd-fcgi-impersonation-' . getmypid();
@mkdir($dir);
exec('icacls ' . escapeshellarg($dir) . ' /grant *S-1-1-0:(OI)(CI)F 2>&1');
$script = "$dir\\index.php";
file_put_contents($script, '<?php echo "hello from php-cgi\n";');
$log = "$dir\\ddtrace.log";

exec("net user $user $password /add 2>&1", $out, $rc);
echo "user created: ", $rc === 0 ? "yes" : "no: " . implode("\n", $out), "\n";

$env = getenv();
unset($env['PHP_FCGI_CHILDREN'], $env['DD_TRACE_CLI_ENABLED']);
$env['DD_SERVICE'] = $service;
$env['DD_TRACE_SIDECAR_TRACE_SENDER'] = '1';
$env['DD_TRACE_AGENT_FLUSH_INTERVAL'] = '100';
$env['DD_TRACE_LOG_FILE'] = $log;
$env['DD_TRACE_LOG_LEVEL'] = 'info';
$env['_DD_DEBUG_SIDECAR_LOG_LEVEL'] = 'debug';
$env['_DD_DEBUG_SIDECAR_LOG_METHOD'] = "file://$dir\\sidecar.log";

$cgi = '"' . dirname(PHP_BINARY) . '\\php-cgi.exe" ' . getenv('TEST_PHP_EXTRA_ARGS')
    . ' -d fastcgi.impersonate=1 -d cgi.force_redirect=0 -b ' . $pipe;
$proc = proc_open($cgi, [['file', 'NUL', 'r'], ['file', "$dir\\cgi.out", 'w'], ['file', "$dir\\cgi.err", 'w']], $pipes, $dir, $env, ['bypass_shell' => true]);

$client = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg(__DIR__ . '\\includes\\fastcgi_impersonating_client.ps1')
    . " -User $user -Password " . escapeshellarg($password) . " -Pipe " . escapeshellarg($pipe) . " -Script " . escapeshellarg($script);
$clientOutput = [];
for ($i = 1; $i <= 2; $i++) {
    // A timed out request means php-cgi is stuck: stop there to keep time for the diagnostics.
    $clientOutput[$i] = $i > 1 && strpos($clientOutput[$i - 1], "client: TIMEOUT") !== false ? "skipped\n" : shell_exec("$client -Uri /request-$i 2>&1");
    echo "request $i: ", strpos($clientOutput[$i], "hello from php-cgi") !== false ? "ok" : "failed", "\n";
    echo "request $i impersonated: ", stripos($clientOutput[$i], "impersonated identity") !== false && stripos($clientOutput[$i], "\\$user") !== false ? "yes" : "no", "\n";
}

$found = false;
for ($i = 0; $i < 50 && !$found; $i++) {
    usleep(200000);
    foreach ($rr->replayAllRequests() ?: [] as $request) {
        if (strpos($request["uri"], "telemetry") === false && strpos($request["body"] ?? "", $service) !== false) {
            $found = true;
        }
    }
}
echo "trace received: ", $found ? "yes" : "no", "\n";

if (!$found) {
    // Diagnostics, shown in the failure diff.
    foreach ($clientOutput as $i => $o) {
        echo "=== client output, request $i ===\n$o\n";
    }
    echo "=== php-cgi command ===\n$cgi\n";
    echo "=== php-cgi status ===\n", var_export(proc_get_status($proc), true), "\n";
    foreach (['ddtrace.log', 'sidecar.log', 'cgi.out', 'cgi.err'] as $f) {
        echo "=== $f ===\n", @file_get_contents("$dir\\$f"), "\n";
    }
    echo "=== whoami /all (test process) ===\n", shell_exec('whoami /all 2>&1'), "\n";
    echo "=== net user $user ===\n", shell_exec("net user $user 2>&1"), "\n";
    echo "=== tasklist /v ===\n", shell_exec('tasklist /v 2>&1'), "\n";
    $tmp = sys_get_temp_dir();
    echo "=== $tmp datadog files ===\n", shell_exec('dir /a ' . escapeshellarg($tmp) . ' 2>&1 | findstr /i "datadog libdatadog"'), "\n";
    $pipeList = shell_exec('powershell.exe -NoProfile -Command "Get-ChildItem \\\\.\\pipe\\ | ForEach-Object Name"');
    echo "=== named pipes ===\n", implode("\n", preg_grep('/datadog|libdatadog|dd-/i', explode("\n", (string)$pipeList))), "\n";
}

exec('taskkill /T /F /PID ' . proc_get_status($proc)['pid'] . ' 2>&1');
proc_close($proc);
exec("net user $user /delete 2>&1");
exec('rd /s /q ' . escapeshellarg($dir) . ' 2>&1');
?>
--EXPECT--
user created: yes
request 1: ok
request 1 impersonated: yes
request 2: ok
request 2 impersonated: yes
trace received: yes
