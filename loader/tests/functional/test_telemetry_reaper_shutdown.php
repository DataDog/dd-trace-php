<?php

require_once __DIR__."/includes/autoload.php";

if (strpos((string) shell_exec('getconf GNU_LIBC_VERSION 2>/dev/null'), 'glibc ') !== 0) {
    echo "Skip: test requires glibc exit handlers\n";
    exit(0);
}

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');
$outputPath = tempnam(sys_get_temp_dir(), 'test_loader_');
$errorPath = tempnam(sys_get_temp_dir(), 'test_loader_');
$fixturePath = tempnam(sys_get_temp_dir(), 'test_loader_');
$releasePath = $telemetryLogPath.'.release';
$exitPath = $telemetryLogPath.'.exit';
$process = null;

try {
    $compiler = getenv('CC') ?: trim((string) shell_exec('command -v cc || command -v clang'));
    if ($compiler === '') {
        throw new \Exception('A C compiler is required for the shutdown fixture');
    }
    $compile = sprintf(
        '%s -std=gnu11 -O2 -Wall -Wextra -Werror -fPIC -shared -pthread %s -ldl -o %s 2>&1',
        escapeshellarg($compiler),
        escapeshellarg(__DIR__.'/fixtures/reaper_shutdown.c'),
        escapeshellarg($fixturePath)
    );
    exec($compile, $compilerOutput, $compilerStatus);
    if ($compilerStatus !== 0) {
        throw new \Exception('Cannot build shutdown fixture: '.implode("\n", $compilerOutput));
    }

    // An empty package leaves only telemetry children, all held at the gate.
    // Count them before shutdown so we can wait for every forwarder afterwards.
    $code = '$children = trim(file_get_contents("/proc/self/task/".getmypid()."/children"));'.
        'echo $children === "" ? 0 : count(explode(" ", $children));';
    $command = sprintf(
        'exec env DD_TRACE_DEBUG=0 DD_LOADER_PACKAGE_PATH=/nonexistent-ddloader-test '.
        'LD_PRELOAD=%s DD_REAPER_TEST_PID=$$ DD_REAPER_TEST_EXIT_PATH=%s '.
        'FAKE_FORWARDER_RELEASE_PATH=%s FAKE_FORWARDER_LOG_PATH=%s '.
        'DD_TELEMETRY_FORWARDER_PATH=%s %s -n -dzend_extension=%s -r %s',
        escapeshellarg($fixturePath),
        escapeshellarg($exitPath),
        escapeshellarg($releasePath),
        escapeshellarg($telemetryLogPath),
        escapeshellarg(__DIR__.'/fixtures/gated_forwarder.sh'),
        escapeshellarg(PHP_BINARY),
        escapeshellarg(getLoaderAbsolutePath()),
        escapeshellarg($code)
    );
    // Files avoid mistaking an inherited output pipe for PHP still running.
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $outputPath, 'w'],
        2 => ['file', $errorPath, 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new \Exception('Failed to start PHP process');
    }

    $enteredExit = false;
    $deadline = microtime(true) + 10;
    do {
        clearstatcache(true, $exitPath);
        if (!$enteredExit && file_exists($exitPath)) {
            // Release telemetry only after Zend shutdown, inside a native exit
            // handler. The old reaper then calls dlclose concurrently with it.
            $enteredExit = true;
            touch($releasePath);
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    if ($status['running']) {
        throw new \Exception('Loader shutdown or telemetry reaping did not finish');
    }
    if ($status['exitcode'] !== 0) {
        throw new \Exception('PHP failed during loader shutdown: '.$status['exitcode']."\n".
            file_get_contents($errorPath));
    }
    if (!$enteredExit || file_get_contents($exitPath) !== "passed\n") {
        throw new \Exception('Native exit handler did not verify telemetry reaping');
    }
    $children = (int) file_get_contents($outputPath);
    if ($children <= 0) {
        throw new \Exception('No telemetry children were started');
    }

    echo "OK: Telemetry reaped during atexit without a background dlclose\n";
} finally {
    // Let the real child processes finish, including on a regression failure.
    touch($releasePath);
    if (is_resource($process)) {
        proc_close($process);
    }
    $children = (int) file_get_contents($outputPath);
    $deadline = microtime(true) + 5;
    do {
        $completed = count(file($telemetryLogPath));
        if ($completed >= $children) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    @unlink($telemetryLogPath);
    @unlink($outputPath);
    @unlink($errorPath);
    @unlink($fixturePath);
    @unlink($releasePath);
    @unlink($exitPath);
    if ($completed < $children) {
        throw new \Exception('Telemetry children did not finish after releasing the gate');
    }
}
