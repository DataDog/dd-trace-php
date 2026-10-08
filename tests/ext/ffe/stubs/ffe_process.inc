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
    $command = (PHP_OS === 'WINNT' ? '' : 'exec ') . escapeshellarg(getenv('TEST_PHP_EXECUTABLE'))
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
