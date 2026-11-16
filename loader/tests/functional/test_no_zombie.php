<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

// Build the command to run PHP with the loader. "exec" makes the proc_open PID
// the PHP PID instead of the intermediate shell PID.
$cmd = sprintf(
    'exec env FAKE_FORWARDER_LOG_PATH=%s DD_TELEMETRY_FORWARDER_PATH=%s %s -n -dzend_extension=%s -r "sleep(1);"',
    escapeshellarg($telemetryLogPath),
    escapeshellarg(__DIR__.'/../../bin/fake_forwarder.sh'),
    escapeshellarg(PHP_BINARY),
    escapeshellarg(getLoaderAbsolutePath())
);

$process = null;
try {
    // Do not use pipes here. A telemetry descendant can inherit them and keep
    // stream_get_contents() waiting for EOF after the PHP process has exited.
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'a'],
        2 => ['file', '/dev/null', 'a'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new \Exception("Failed to start PHP process");
    }

    $status = proc_get_status($process);
    $phpPid = (int)$status['pid'];
    if ($phpPid <= 0) {
        throw new \Exception("Failed to get PHP PID");
    }

    if (debug()) {
        echo "[debug] PHP PID: $phpPid\n";
    }

    // Wait for the telemetry fork to happen and complete.
    usleep(300000);

    $childrenPath = sprintf('/proc/%d/task/%d/children', $phpPid, $phpPid);
    if (!is_readable($childrenPath)) {
        throw new \Exception("Unable to inspect child processes for PHP PID $phpPid");
    }

    $zombies = [];
    $children = preg_split('/\s+/', trim(file_get_contents($childrenPath)), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($children as $childPid) {
        $stat = @file_get_contents('/proc/'.$childPid.'/stat');
        if ($stat === false) {
            continue; // The child was reaped while it was being inspected.
        }

        // /proc/<pid>/stat begins with "pid (comm) state"; comm may contain spaces.
        $stateOffset = strrpos($stat, ') ');
        $state = $stateOffset === false ? null : substr($stat, $stateOffset + 2, 1);
        if ($state === 'Z') {
            $zombies[] = $childPid;
        }
    }

    if ($zombies) {
        throw new \Exception('FAILED: Zombie process detected after telemetry fork: '.implode(', ', $zombies));
    }

    // Bound the test independently of inherited descriptors or descendants.
    $deadline = microtime(true) + 5;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    if ($status['running']) {
        proc_terminate($process);
        throw new \Exception("PHP process did not exit within 5 seconds");
    }

    proc_close($process);
    $process = null;
    echo "OK: No zombie processes detected\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    @unlink($telemetryLogPath);
}
