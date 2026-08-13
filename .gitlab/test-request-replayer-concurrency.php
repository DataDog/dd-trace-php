<?php

declare(strict_types=1);

if (PHP_OS_FAMILY === 'Windows') {
    exit(0);
}

function startProcess(array $command, array $environment, string $outputFile)
{
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
        $pipes,
        dirname(__DIR__),
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start: ' . implode(' ', $command));
    }
    fclose($pipes[0]);
    return $process;
}

function stopProcess($process): void
{
    if ($process === null) {
        return;
    }
    if (proc_get_status($process)['running']) {
        proc_terminate($process);
    }
    proc_close($process);
}

function request(string $url, string $token = '', float $timeout = 2): string
{
    $headers = $token === '' ? '' : "X-Datadog-Test-Session-Token: $token";
    $response = @file_get_contents($url, false, stream_context_create([
        'http' => ['header' => $headers, 'timeout' => $timeout],
    ]));
    if ($response === false) {
        throw new RuntimeException("Request failed: $url");
    }
    return $response;
}

function openPostClient(int $port, string $token, string $body)
{
    $client = stream_socket_client("tcp://127.0.0.1:$port", $errorCode, $errorMessage, 1);
    if ($client === false) {
        throw new RuntimeException("Failed to connect client: $errorMessage ($errorCode)");
    }
    $length = strlen($body);
    fwrite(
        $client,
        "POST /v0.4/traces HTTP/1.1\r\nHost: 127.0.0.1\r\n"
            . "X-Datadog-Test-Session-Token: $token\r\nContent-Type: application/json\r\n"
            . "Content-Length: $length\r\nConnection: close\r\n\r\n$body"
    );
    stream_set_timeout($client, 10);
    return $client;
}

$repoRoot = dirname(__DIR__);
$generatedYaml = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repoRoot . '/.gitlab/generate-tracer.php'));
if ($generatedYaml === null) {
    throw new RuntimeException('Failed to generate the tracer pipeline');
}
$workerCount = preg_match('/^\s+PHP_CLI_SERVER_WORKERS:\s*["\']?(\d+)["\']?\s*$/m', $generatedYaml, $matches)
    ? (int) $matches[1]
    : 1;
if ($workerCount <= 12) {
    throw new RuntimeException("Request-replayer needs more than 12 workers, got $workerCount");
}
foreach ([
    'install-request-replayer-source.sh',
    'request-replayer-router.php',
    'request-replayer-ready',
    'REQUEST_REPLAYER_METRICS_SERVER_MANAGED: "1"',
    'while true; do php /var/www/metricsserver.php; sleep 1; done',
    'echo "$!" > /tmp/metrics-server.pid',
] as $expected) {
    if (!str_contains($generatedYaml, $expected)) {
        throw new RuntimeException("Generated request-replayer service is missing: $expected");
    }
}

$server = null;
$installer = null;
$clients = [];
$lock = null;
$temporaryPaths = [];

try {
    $stateDirectory = sys_get_temp_dir() . '/request-replayer-state-' . getmypid();
    if (!mkdir($stateDirectory)) {
        throw new RuntimeException("Failed to create $stateDirectory");
    }
    $temporaryPaths[] = $stateDirectory;

    $installSource = $stateDirectory . '/install-source';
    $installTarget = $stateDirectory . '/install-target';
    $installReady = $stateDirectory . '/install-ready';
    mkdir($installSource);
    $installer = startProcess([
        'sh',
        $repoRoot . '/.gitlab/install-request-replayer-source.sh',
        $installSource,
        $installTarget,
        $installReady,
    ], getenv(), $stateDirectory . '/installer.log');
    file_put_contents($installSource . '/state-lock.php', 'state lock');
    usleep(200_000);
    if (file_exists($installReady)) {
        throw new RuntimeException('Installer completed without index.php or a writable destination');
    }
    file_put_contents($installSource . '/index.php', 'router');
    usleep(1_100_000);
    if (file_exists($installReady)) {
        throw new RuntimeException('Installer did not remain fail-closed after a copy failure');
    }
    mkdir($installTarget);
    $deadline = microtime(true) + 3;
    while (!file_exists($installReady) && microtime(true) < $deadline) {
        usleep(20_000);
    }
    if (!file_exists($installReady)
        || file_get_contents($installTarget . '/index.php') !== 'router'
        || file_get_contents($installTarget . '/state-lock.php') !== 'state lock'
    ) {
        throw new RuntimeException('Installer did not recover and install both source files');
    }
    stopProcess($installer);
    $installer = null;

    $docroot = $stateDirectory . '/docroot';
    if (!mkdir($docroot . '/vendor', 0777, true)) {
        throw new RuntimeException("Failed to create $docroot");
    }
    copy($repoRoot . '/dockerfiles/services/request-replayer/src/index.php', $docroot . '/index.php');
    copy($repoRoot . '/dockerfiles/services/request-replayer/src/state-lock.php', $docroot . '/state-lock.php');
    file_put_contents($docroot . '/vendor/autoload.php', "<?php\n");

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($listener === false) {
        throw new RuntimeException("Failed to reserve a port: $errorMessage ($errorCode)");
    }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $port = (int) substr((string) strrchr((string) $address, ':'), 1);
    $baseUrl = "http://127.0.0.1:$port";

    $environment = getenv();
    $environment['PHP_CLI_SERVER_WORKERS'] = (string) $workerCount;
    $environment['REQUEST_REPLAYER_METRICS_SERVER_MANAGED'] = '1';
    $environment['TMPDIR'] = $stateDirectory;
    $server = startProcess(
        [PHP_BINARY, '-n', '-S', "127.0.0.1:$port", $docroot . '/index.php'],
        $environment,
        $stateDirectory . '/server.log'
    );

    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        try {
            request("$baseUrl/replay", 'ready', 0.1);
            $ready = true;
            break;
        } catch (RuntimeException $exception) {
            usleep(20_000);
        }
    }
    if (!$ready) {
        throw new RuntimeException('Request-replayer test server did not become ready');
    }

    for ($id = 0; $id < 12; $id++) {
        $clients[] = openPostClient($port, 'shared', json_encode(['id' => $id]));
    }
    foreach ($clients as $client) {
        stream_get_contents($client);
        fclose($client);
    }
    $clients = [];

    $requests = json_decode(request("$baseUrl/replay", 'shared'), true, flags: JSON_THROW_ON_ERROR);
    $ids = array_map(static function (array $request): int {
        return (int) json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR)['id'];
    }, $requests);
    sort($ids, SORT_NUMERIC);
    if ($ids !== range(0, 11)) {
        throw new RuntimeException('Concurrent same-session requests were lost');
    }

    $slowStateDirectory = $stateDirectory . '/token-slow';
    if (!mkdir($slowStateDirectory)) {
        throw new RuntimeException("Failed to create $slowStateDirectory");
    }
    $lock = fopen($slowStateDirectory . '/.state.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Failed to hold the slow-session state lock');
    }
    $slowClient = openPostClient($port, 'slow', '{}');
    $clients[] = $slowClient;
    usleep(200_000);
    $read = [$slowClient];
    $write = null;
    $except = null;
    if (stream_select($read, $write, $except, 0, 0) !== 0) {
        throw new RuntimeException('Production request did not block on its session lock');
    }
    if (request("$baseUrl/replay", 'other', 1) !== '') {
        throw new RuntimeException('Unexpected replay data for the other session');
    }
    fclose($lock);
    $lock = null;
    stream_get_contents($slowClient);
    fclose($slowClient);
    $clients = [];

    echo "request-replayer production locking passed with $workerCount workers\n";
} catch (Throwable $exception) {
    foreach (['installer.log', 'server.log'] as $logName) {
        $log = $stateDirectory . '/' . $logName;
        if (file_exists($log) && filesize($log) > 0) {
            fwrite(STDERR, "=== $logName ===\n" . file_get_contents($log));
        }
    }
    throw $exception;
} finally {
    stopProcess($installer);
    if (is_resource($lock)) {
        fclose($lock);
    }
    foreach ($clients as $client) {
        fclose($client);
    }
    stopProcess($server);
    foreach (array_reverse($temporaryPaths) as $path) {
        if (!is_dir($path)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $child) {
            $child->isDir() ? rmdir($child->getPathname()) : unlink($child->getPathname());
        }
        rmdir($path);
    }
}
