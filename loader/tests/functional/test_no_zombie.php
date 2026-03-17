<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

// Build the command to run PHP with the loader
$cmd = sprintf(
    'FAKE_FORWARDER_LOG_PATH=%s DD_TELEMETRY_FORWARDER_PATH=%s php -n -dzend_extension=%s -r "sleep(1);"',
    escapeshellarg($telemetryLogPath),
    escapeshellarg(__DIR__.'/../../bin/fake_forwarder.sh'),
    escapeshellarg(getLoaderAbsolutePath())
);

try {
    // Start the PHP process in background and get its PID
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd . ' & echo $!', $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new \Exception("Failed to start PHP process");
    }

    // Get the real PHP PID from the output
    $firstLine = fgets($pipes[1]);
    $phpPid = (int)trim($firstLine);

    if ($phpPid <= 0) {
        throw new \Exception("Failed to get PHP PID");
    }

    if (debug()) {
        echo "[debug] PHP PID: $phpPid\n";
    }

    // Wait for the telemetry fork to happen and complete
    usleep(300000); // 300ms

    // Check for zombie processes that are children of the PHP process
    $zombieCheckCmd = sprintf('ps --ppid %d -o pid,state,comm --no-headers 2>/dev/null || echo "NO_CHILDREN"', $phpPid);
    $zombieOutput = shell_exec($zombieCheckCmd);

    if (debug()) {
        echo "[debug] Children processes:\n" . $zombieOutput . "\n";
    }

    $zombieCount = substr_count($zombieOutput, ' Z ');

    // Wait for the PHP process to finish
    $waitCmd = sprintf('wait %d 2>/dev/null; echo $?', $phpPid);
    $phpExitCode = (int)trim(shell_exec($waitCmd));

    // Read the remaining output and close pipes
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if ($zombieCount > 0) {
        throw new \Exception("FAILED: Zombie process detected after telemetry fork! Found $zombieCount zombie(s)");
    }

    echo "OK: No zombie processes detected\n";
} finally {
    @unlink($telemetryLogPath);
}
