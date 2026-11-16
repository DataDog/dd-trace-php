<?php

function ffeTestProcess($script, &$pipes, $environment = null)
{
    // Windows anonymous pipes cannot provide the non-blocking reads needed
    // while the parent also serves the child's HTTP requests. Separate file
    // handles let the parent consume only the output currently available.
    $logs = array(tempnam(sys_get_temp_dir(), 'php-ffe-out-'), tempnam(sys_get_temp_dir(), 'php-ffe-err-'));
    if (in_array(false, $logs, true)) throw new RuntimeException('cannot create child output files');
    register_shutdown_function(function () use ($logs) {
        foreach ($logs as $log) @unlink($log);
    });
    $command = (PHP_OS === 'WINNT' ? '' : 'exec ') . escapeshellarg(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY)
        . ' ' . getenv('TEST_PHP_EXTRA_ARGS') . ' ' . escapeshellarg($script);
    // Avoid cmd.exe stripping the executable's quotes on older Windows PHP.
    $process = proc_open($command, array(array('pipe', 'r'), array('file', $logs[0], 'w'), array('file', $logs[1], 'w')),
        $pipes, null, $environment, array('bypass_shell' => true));
    if (!is_resource($process)) throw new RuntimeException('cannot launch PHP child');
    $pipes[1] = fopen($logs[0], 'r');
    $pipes[2] = fopen($logs[1], 'r');
    return $process;
}

function ffeTestProcessRead($stream)
{
    // Clear PHP's EOF state before checking whether the child appended output.
    if (fseek($stream, ftell($stream)) !== 0) throw new RuntimeException('cannot read child output');
    return stream_get_contents($stream);
}

function ffeTestStreamContains($stream, $needle)
{
    if (!rewind($stream)) throw new RuntimeException('cannot rewind diagnostic log');
    $overlap = strlen($needle) - 1;
    $tail = '';
    while (!feof($stream)) {
        $chunk = fread($stream, 8192);
        if ($chunk === false) throw new RuntimeException('cannot read diagnostic log');
        $chunk = $tail . $chunk;
        if (strpos($chunk, $needle) !== false) return true;
        // Retain enough bytes to find a marker split across two reads.
        $tail = $overlap > 0 ? substr($chunk, -$overlap) : '';
    }
    return false;
}

// Match only the dedicated sidecar listener owned by this test child.
function ffeTestSidecarPid($childPid) {
    foreach (file('/proc/net/unix') as $row) {
        $columns = preg_split('/\s+/', trim($row));
        if (!isset($columns[7]) || $columns[3] !== '00010000'
            || strpos($columns[7], '@libdatadog/') !== 0
            || substr($columns[7], -strlen('.' . $childPid . '.sock')) !== '.' . $childPid . '.sock') continue;
        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) as $directory) {
            if (@fileowner($directory) !== posix_geteuid()) continue;
            foreach (glob($directory . '/fd/*') as $descriptor) {
                if (@readlink($descriptor) !== 'socket:[' . $columns[6] . ']') continue;
                $pid = (int)basename($directory);
                if ($pid === $childPid || $pid === getmypid()) throw new RuntimeException('sidecar listener is in a PHP application process');
                return $pid;
            }
        }
    }
    return null;
}
