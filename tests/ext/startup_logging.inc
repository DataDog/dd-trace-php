<?php

function dd_get_php_cgi()
{
    $executable = getenv('TEST_PHP_CGI_EXECUTABLE');
    if (!$executable) {
        $executable = getenv('TEST_PHP_EXECUTABLE');
        if (@substr($executable, -4) === ".exe") {
            $executable = substr($executable, -4) . "-cgi.exe";
        } else {
            $executable .= '-cgi';
        }
    }
    if (file_exists($executable) && is_executable($executable)) {
        $args = getenv('TEST_PHP_ARGS');
        return $args ? "$executable $args" : "$executable";
    }
    return '';
}

function dd_get_startup_logs(array $args = [], array $env = [])
{
    // If we don't reset these before executing php-cgi, the test will hang
    // @see https://github.com/php/php-src/blob/16f194c75e/sapi/cgi/tests/include.inc#L53-L63
    putenv("REDIRECT_STATUS");
    putenv("QUERY_STRING");
    putenv("PATH_TRANSLATED");
    putenv("SCRIPT_FILENAME");
    putenv("SERVER_SOFTWARE");
    putenv("SERVER_NAME");
    putenv("GATEWAY_INTERFACE");
    putenv("REQUEST_METHOD");

    $argList = implode(' ', $args);
    $cgi = dd_get_php_cgi();
    $cmd = $cgi . ' ' . getenv("TEST_PHP_EXTRA_ARGS") . ' ' . $argList . ' -v 2>&1';
    $proc = proc_open($cmd, [["pipe", "r"], ["pipe", "w"], ["pipe", "w"]], $pipes, null, $env);
    $o = stream_get_contents($pipes[1]);

    $target = 'DATADOG TRACER CONFIGURATION - ';
    $json = '';
    foreach (explode("\n", $o) as $line) {
        $pos = strpos($line, $target);
        if ($pos !== false) {
            $json = substr($line, $pos + strlen($target));
            break;
        }
    }

    if (!$json) {
        echo $cmd . PHP_EOL;
        echo 'No JSON found: (' . str_replace($o, "\n", '; ') . ')' . PHP_EOL;
        return [];
    }
    $logs = json_decode($json, true);
    if (!$logs) {
        echo $cmd . PHP_EOL;
        echo 'Invalid JSON: (' . $json . ')' . PHP_EOL;
        return [];
    }
    return $logs;
}

function dd_dump_startup_logs($logs, array $only = [])
{
    foreach ($logs as $k => $v) {
        if ($only && !in_array($k, $only, true)) {
            continue;
        }
        echo $k . ': ';
        if (is_string($v)) {
            echo '"' . $v . '"';
        } elseif (is_bool($v)) {
            echo $v ? 'true' : 'false';
        } elseif (is_null($v)) {
            echo 'null';
        } elseif (is_int($v)) {
            echo $v;
        } elseif (is_float($v)) {
            printf('%.4f', $v);
        } elseif (is_array($v)) {
            echo json_encode($v);
        }
        echo PHP_EOL;
    }
}
